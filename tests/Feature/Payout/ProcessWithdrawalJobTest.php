<?php

declare(strict_types=1);

namespace Tests\Feature\Payout;

use App\Mail\WithdrawalRejectedEmail;
use App\Mail\WithdrawalSentEmail;
use App\Models\BalanceLedger;
use App\Models\User;
use App\Models\Withdrawal;
use App\Payout\FaucetPayClient;
use App\Payout\FaucetPayUnreachableException;
use App\Payout\ProcessWithdrawalJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks the FaucetPay payout retry / dead-letter contract.
 *
 *   - Successful send → status='sent', payout_id captured, user counters
 *     bumped, success email queued.
 *   - Permanent failure (FaucetPay returned ok=false) → status='failed',
 *     balance refunded, rejection email queued. NO retry — we cannot tell
 *     whether FaucetPay processed the payout.
 *   - {@see FaucetPayUnreachableException} (server unreachable, request
 *     never sent over the wire) escapes `handle()` so Laravel's retry
 *     machinery re-enqueues with backoff.
 *   - Dead-letter (failed() callback): when $tries is exhausted or any
 *     unhandled exception bubbles up, the user is refunded + notified.
 *     Funds never end up silently stranded mid-flight.
 *   - requires_review=true short-circuits to status='hold' without
 *     touching FaucetPay.
 */
class ProcessWithdrawalJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_send_marks_sent_and_increments_total_withdrawn(): void
    {
        Mail::fake();
        $user = User::factory()->create(['balance_sat' => 0, 'total_withdrawn_sat' => 0]);
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 5000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            'status' => 'queued',
        ]);

        $client = $this->fakeClient(['ok' => true, 'payout_id' => 'PO-1', 'message' => 'sent', 'raw' => ['status' => 200]]);

        (new ProcessWithdrawalJob($w->id))->handle($client);

        $w->refresh();
        $this->assertSame('sent', $w->status);
        $this->assertSame('PO-1', $w->faucetpay_payout_id);
        $this->assertSame(5000, (int) $user->fresh()->total_withdrawn_sat);
        Mail::assertQueued(WithdrawalSentEmail::class);
    }

    public function test_permanent_failure_marks_failed_and_refunds(): void
    {
        Mail::fake();
        $user = User::factory()->create(['balance_sat' => 0]);
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 5000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            'status' => 'queued',
        ]);

        // FaucetPay returned a non-200 — request reached the server, so we
        // do NOT retry. Mark failed + refund.
        $client = $this->fakeClient([
            'ok' => false,
            'payout_id' => null,
            'message' => 'Insufficient balance',
            'raw' => ['status' => 405],
        ]);

        (new ProcessWithdrawalJob($w->id))->handle($client);

        $w->refresh();
        $this->assertSame('failed', $w->status);
        $this->assertSame('Insufficient balance', $w->failure_reason);
        $this->assertSame(5000, (int) $user->fresh()->balance_sat);
        $this->assertDatabaseHas('balance_ledgers', [
            'user_id' => $user->id,
            'delta_sat' => 5000,
            'reason' => 'withdraw_refund',
        ]);
        Mail::assertQueued(WithdrawalRejectedEmail::class);
    }

    public function test_unreachable_exception_propagates_so_laravel_can_retry(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 5000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            'status' => 'queued',
        ]);

        $client = $this->throwingClient(new FaucetPayUnreachableException('connection refused'));

        $this->expectException(FaucetPayUnreachableException::class);

        try {
            (new ProcessWithdrawalJob($w->id))->handle($client);
        } finally {
            // Status is left as 'processing' (we marked it so before send),
            // attempts counter has been recorded — operator dashboard sees
            // a real "in flight, retrying" indicator. NO refund yet because
            // the retry might still succeed.
            $w->refresh();
            $this->assertSame('processing', $w->status);
            $this->assertSame(1, (int) ($w->meta['attempts'] ?? 0));
            // No balance_ledger refund row was written.
            $this->assertDatabaseMissing('balance_ledgers', [
                'user_id' => $user->id,
                'reason' => 'withdraw_refund',
            ]);
        }
    }

    public function test_failed_callback_refunds_user_after_tries_exhausted(): void
    {
        Mail::fake();
        $user = User::factory()->create(['balance_sat' => 0, 'total_withdrawn_sat' => 0]);
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 5000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            // Simulate state after handle() marked it processing on a prior attempt.
            'status' => 'processing',
            'meta' => ['attempts' => 3],
        ]);

        (new ProcessWithdrawalJob($w->id))->failed(new FaucetPayUnreachableException('still down'));

        $w->refresh();
        $this->assertSame('failed', $w->status);
        $this->assertStringContainsString('job_failed', (string) $w->failure_reason);
        $this->assertSame(5000, (int) $user->fresh()->balance_sat);
        $this->assertDatabaseHas('balance_ledgers', [
            'user_id' => $user->id,
            'delta_sat' => 5000,
            'reason' => 'withdraw_refund',
        ]);
        Mail::assertQueued(WithdrawalRejectedEmail::class);
    }

    public function test_failed_callback_is_idempotent_on_already_settled_withdrawal(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        // The job's handle() succeeded earlier and moved the row to 'sent';
        // a stray failed() invocation must NOT double-refund.
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 5000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            'status' => 'sent',
            'faucetpay_payout_id' => 'PO-1',
        ]);

        (new ProcessWithdrawalJob($w->id))->failed(new RuntimeException('stray exception'));

        $this->assertSame('sent', $w->fresh()->status);
        $this->assertDatabaseMissing('balance_ledgers', [
            'user_id' => $user->id,
            'reason' => 'withdraw_refund',
        ]);
    }

    public function test_requires_review_short_circuits_without_calling_faucetpay(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 5000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            'status' => 'queued',
            'requires_review' => true,
        ]);

        // Client must never be touched.
        $client = $this->throwingClient(new RuntimeException('should not be called'));

        (new ProcessWithdrawalJob($w->id))->handle($client);

        $this->assertSame('hold', $w->fresh()->status);
        $this->assertDatabaseMissing('balance_ledgers', [
            'user_id' => $user->id,
            'reason' => 'withdraw_refund',
        ]);
    }

    public function test_atomic_claim_aborts_when_row_already_processing_and_then_sent(): void
    {
        // Two-worker race simulation: worker A claims and settles the
        // withdrawal between worker B reading it and worker B trying
        // to call FaucetPay. The atomic-claim WHERE filter must catch
        // worker B and abort — no double FaucetPay call, no
        // total_withdrawn_sat double-bump.
        Mail::fake();
        $user = User::factory()->create(['total_withdrawn_sat' => 0]);
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 7500,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            'status' => 'queued',
        ]);

        // Worker A's effect: row already says `sent` by the time worker B
        // gets to its UPDATE WHERE status IN (queued, processing).
        $user->forceFill(['total_withdrawn_sat' => 7500])->save();
        $w->update([
            'status' => 'sent',
            'faucetpay_payout_id' => 'PO-by-A',
            'processed_at' => now(),
        ]);

        // Worker B fires now. The throwing client guarantees we'd
        // detect any FaucetPay call (which must NOT happen).
        $clientBNeverCalled = $this->throwingClient(new RuntimeException('FaucetPay must not be called for an already-settled row'));
        (new ProcessWithdrawalJob($w->id))->handle($clientBNeverCalled);

        $w = $w->fresh();
        $user = $user->fresh();
        $this->assertSame('sent', $w->status);
        $this->assertSame('PO-by-A', $w->faucetpay_payout_id, "worker A's payout id must not be overwritten");
        $this->assertSame(7500, (int) $user->total_withdrawn_sat, 'must not double-count');
    }

    public function test_settle_path_is_idempotent_when_status_flipped_before_settle(): void
    {
        // Simulates the second (rare) race: worker B passes the atomic
        // claim AND calls FaucetPay successfully, but worker A's settle
        // races in just before worker B's UPDATE for status='sent'.
        // The settle predicate `WHERE status='processing'` must filter
        // worker B's UPDATE → 0 rows → skip the total_withdrawn_sat
        // increment.
        Mail::fake();
        $user = User::factory()->create(['total_withdrawn_sat' => 0]);
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 4200,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            'status' => 'processing',  // pre-claimed so handle() skips its own claim path
        ]);

        // Force the row to `sent` mid-flight to mimic worker A's settle.
        // We do this via a fake client whose send() side-effects the row.
        $client = new class($w->id) extends FaucetPayClient
        {
            public function __construct(private readonly int $id)
            {
                // Skip parent.
            }

            public function send(string $faucetpayEmail, int $amountSat, string $currency = 'BTC', string $referenceId = ''): array
            {
                // Mid-flight: worker A finishes its settle path before us.
                Withdrawal::where('id', $this->id)->update([
                    'status' => 'sent',
                    'faucetpay_payout_id' => 'PO-from-A',
                    'processed_at' => now(),
                ]);

                // Then worker B's FaucetPay call returns ok with its own payout id.
                return [
                    'ok' => true,
                    'payout_id' => 'PO-from-B',
                    'message' => 'ok',
                    'raw' => ['payout_id' => 'PO-from-B'],
                ];
            }
        };

        (new ProcessWithdrawalJob($w->id))->handle($client);

        $w = $w->fresh();
        $user = $user->fresh();
        $this->assertSame('sent', $w->status);
        // Worker A wrote PO-from-A; worker B's update was filtered by
        // the WHERE status='processing' predicate so PO-from-A survives.
        $this->assertSame('PO-from-A', $w->faucetpay_payout_id);
        // Crucially: total_withdrawn_sat was NOT incremented by worker B
        // because the affected_rows check returned 0.
        $this->assertSame(0, (int) $user->total_withdrawn_sat);
    }

    public function test_failure_refund_path_is_idempotent_under_double_invocation(): void
    {
        // markFailedAndRefund() can be reached twice in pathological
        // scenarios (job retry storm + dead-letter, or two parallel
        // workers). The atomic UPDATE filter + balance_ledgers UNIQUE
        // backstop must keep the user's balance from double-refunding.
        Mail::fake();
        $user = User::factory()->create(['balance_sat' => 0]);
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 3300,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            'status' => 'queued',
        ]);

        // First run: FaucetPay returns ok=false → refund path fires.
        $client = $this->fakeClient(['ok' => false, 'payout_id' => null, 'message' => 'declined', 'raw' => []]);
        (new ProcessWithdrawalJob($w->id))->handle($client);

        $this->assertSame('failed', $w->fresh()->status);
        $this->assertSame(3300, (int) $user->fresh()->balance_sat, 'first refund credited');

        // Second run: simulates a second worker re-entering after the
        // first finished. The early `! in_array($status)` check would
        // catch this in normal flow, but if it didn't, the atomic
        // refund's WHERE filter would. Here we drive the path
        // directly by re-invoking handle() against the now-failed row.
        (new ProcessWithdrawalJob($w->id))->handle($client);

        $this->assertSame(3300, (int) $user->fresh()->balance_sat, 'must not double-refund');
        $this->assertSame(1, BalanceLedger::where('reference_type', Withdrawal::class)
            ->where('reference_id', $w->id)
            ->where('reason', 'withdraw_refund')
            ->count(), 'exactly one refund ledger row per withdrawal');
    }

    public function test_settled_withdrawal_is_skipped_on_late_dispatch(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 5000,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            'status' => 'sent',
            'faucetpay_payout_id' => 'PO-prior',
        ]);
        $balanceBefore = (int) $user->balance_sat;

        $client = $this->throwingClient(new RuntimeException('should not be called'));
        (new ProcessWithdrawalJob($w->id))->handle($client);

        $this->assertSame('sent', $w->fresh()->status);
        $this->assertSame($balanceBefore, (int) $user->fresh()->balance_sat);
        Mail::assertNothingQueued();
    }

    /**
     * @param  array{ok: bool, payout_id: ?string, message: string, raw: array}  $result
     */
    private function fakeClient(array $result): FaucetPayClient
    {
        return new class($result) extends FaucetPayClient
        {
            /** @param array{ok: bool, payout_id: ?string, message: string, raw: array} $result */
            public function __construct(private readonly array $result)
            {
                // Skip parent constructor — Guzzle Client unused on the test path.
            }

            public function send(string $faucetpayEmail, int $amountSat, string $currency = 'BTC', string $referenceId = ''): array
            {
                return $this->result;
            }
        };
    }

    private function throwingClient(\Throwable $e): FaucetPayClient
    {
        return new class($e) extends FaucetPayClient
        {
            public function __construct(private readonly \Throwable $e)
            {
                // Skip parent constructor.
            }

            public function send(string $faucetpayEmail, int $amountSat, string $currency = 'BTC', string $referenceId = ''): array
            {
                throw $this->e;
            }
        };
    }
}
