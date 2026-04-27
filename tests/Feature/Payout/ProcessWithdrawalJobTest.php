<?php

declare(strict_types=1);

namespace Tests\Feature\Payout;

use App\Mail\WithdrawalRejectedEmail;
use App\Mail\WithdrawalSentEmail;
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
