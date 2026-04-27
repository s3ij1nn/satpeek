<?php

namespace Tests\Feature\Webhook;

use App\Models\BalanceLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the BitcoTasks postback contract against the published spec
 * (https://bitcotasks.com/documentations, fetched 2026-04-27):
 *   - md5(subId.transId.reward.secret) signature, form-encoded
 *   - response is the literal lowercase string "ok"
 *   - status=1 credits, status=2 chargebacks (negative ledger row)
 *   - duplicate transId is a no-op (idempotency via unique index on
 *     balance_ledgers.reason + external_ref)
 *   - debug=1 acks without crediting
 *   - non-allowlisted IP refuses with 401
 *   - bad signature refuses with 401
 *   - unknown subId acks (so BitcoTasks stops retrying) but credits nothing
 */
class BitcoTaskPostbackTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_s2s_secret_xyz';

    private const POSTBACK_IP = '45.14.135.48';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('satpeek.bitcotask', [
            'publisher_id' => 'PUB-TEST',
            'api_key' => 'KEY-TEST',
            'api_base' => 'https://bitcotasks.com',
            's2s_secret' => self::SECRET,
            // 100k sat per USD — round number for assertions.
            'usd_to_sat' => 100000.0,
            'ip_allowlist' => [self::POSTBACK_IP],
        ]);
    }

    public function test_credits_user_on_status_1_and_returns_literal_ok(): void
    {
        $user = User::factory()->create(['balance_sat' => 0, 'total_earned_sat' => 0]);
        $payload = $this->signedPayload([
            'subId' => (string) $user->id,
            'transId' => 'BT-credit-1',
            'reward' => '1.25',
            'reward_name' => 'Points',
            'reward_value' => '1000.00',
            'payout' => '0.10', // 0.10 USD * 100000 sat/USD = 10000 sat
            'userIp' => '203.0.113.10',
            'country' => 'US',
            'status' => '1',
            'debug' => '0',
            'offer_name' => 'Test offer',
            'offer_type' => 'task',
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::POSTBACK_IP])
            ->post('/webhooks/bitcotask', $payload);

        $response->assertOk();
        $this->assertSame('ok', $response->getContent(), 'BitcoTasks treats anything other than literal "ok" as failure and retries');

        $this->assertSame(10000, (int) $user->fresh()->balance_sat);
        $this->assertSame(10000, (int) $user->fresh()->total_earned_sat);
        $this->assertDatabaseHas('balance_ledgers', [
            'user_id' => $user->id,
            'delta_sat' => 10000,
            'reason' => 'bitcotask_postback',
            'external_ref' => 'BT-credit-1',
        ]);
    }

    public function test_chargeback_subtracts_balance_on_status_2(): void
    {
        $user = User::factory()->create(['balance_sat' => 50000]);
        $payload = $this->signedPayload([
            'subId' => (string) $user->id,
            'transId' => 'BT-chargeback-1',
            'reward' => '1.25',
            'payout' => '0.30', // 30000 sat negative
            'status' => '2',
            'debug' => '0',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => self::POSTBACK_IP])
            ->post('/webhooks/bitcotask', $payload)
            ->assertOk();

        $this->assertSame(20000, (int) $user->fresh()->balance_sat);
        $this->assertDatabaseHas('balance_ledgers', [
            'user_id' => $user->id,
            'delta_sat' => -30000,
            'reason' => 'bitcotask_postback',
            'external_ref' => 'BT-chargeback-1',
        ]);
    }

    public function test_duplicate_transid_is_idempotent(): void
    {
        $user = User::factory()->create(['balance_sat' => 0]);
        $payload = $this->signedPayload([
            'subId' => (string) $user->id,
            'transId' => 'BT-dup-1',
            'reward' => '1.0',
            'payout' => '0.05', // 5000 sat
            'status' => '1',
            'debug' => '0',
        ]);

        // First arrival credits.
        $this->withServerVariables(['REMOTE_ADDR' => self::POSTBACK_IP])
            ->post('/webhooks/bitcotask', $payload)
            ->assertOk();
        $this->assertSame(5000, (int) $user->fresh()->balance_sat);

        // Second arrival with the SAME transId acks but does NOT
        // re-credit. Same balance, single ledger row.
        $this->withServerVariables(['REMOTE_ADDR' => self::POSTBACK_IP])
            ->post('/webhooks/bitcotask', $payload)
            ->assertOk();
        $this->assertSame(5000, (int) $user->fresh()->balance_sat);
        $this->assertSame(1, BalanceLedger::where('external_ref', 'BT-dup-1')->count());
    }

    public function test_debug_postback_acks_without_crediting(): void
    {
        $user = User::factory()->create(['balance_sat' => 0]);
        $payload = $this->signedPayload([
            'subId' => (string) $user->id,
            'transId' => 'BT-debug-1',
            'reward' => '1.0',
            'payout' => '0.50',
            'status' => '1',
            'debug' => '1', // test postback
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => self::POSTBACK_IP])
            ->post('/webhooks/bitcotask', $payload)
            ->assertOk();

        $this->assertSame(0, (int) $user->fresh()->balance_sat);
        $this->assertSame(0, BalanceLedger::where('external_ref', 'BT-debug-1')->count());
    }

    public function test_signature_mismatch_returns_401(): void
    {
        $user = User::factory()->create();
        $payload = [
            'subId' => (string) $user->id,
            'transId' => 'BT-bad-sig',
            'reward' => '1.0',
            'payout' => '0.10',
            'status' => '1',
            'debug' => '0',
            'signature' => str_repeat('0', 32), // wrong MD5
        ];

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::POSTBACK_IP])
            ->post('/webhooks/bitcotask', $payload);

        $response->assertStatus(401);
        $this->assertSame(0, BalanceLedger::count());
    }

    public function test_postback_from_non_allowlisted_ip_returns_401(): void
    {
        $user = User::factory()->create();
        $payload = $this->signedPayload([
            'subId' => (string) $user->id,
            'transId' => 'BT-bad-ip',
            'reward' => '1.0',
            'payout' => '0.10',
            'status' => '1',
            'debug' => '0',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->post('/webhooks/bitcotask', $payload)
            ->assertStatus(401);

        $this->assertSame(0, BalanceLedger::count());
    }

    public function test_unknown_subid_acks_but_credits_nothing(): void
    {
        $payload = $this->signedPayload([
            'subId' => '999999', // no such user
            'transId' => 'BT-unknown-user',
            'reward' => '1.0',
            'payout' => '0.10',
            'status' => '1',
            'debug' => '0',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => self::POSTBACK_IP])
            ->post('/webhooks/bitcotask', $payload)
            ->assertOk();

        // Ack returned so BitcoTasks stops retrying, but no ledger row.
        $this->assertSame(0, BalanceLedger::count());
    }

    public function test_unknown_status_code_acks_without_crediting(): void
    {
        // status=3 isn't in the spec; we must not silently double-credit
        // if BitcoTasks adds a new status without us noticing.
        $user = User::factory()->create(['balance_sat' => 0]);
        $payload = $this->signedPayload([
            'subId' => (string) $user->id,
            'transId' => 'BT-status-3',
            'reward' => '1.0',
            'payout' => '0.10',
            'status' => '3',
            'debug' => '0',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => self::POSTBACK_IP])
            ->post('/webhooks/bitcotask', $payload)
            ->assertOk();

        $this->assertSame(0, (int) $user->fresh()->balance_sat);
        $this->assertSame(0, BalanceLedger::count());
    }

    /**
     * Build a payload with the documented MD5 signature derived from
     * subId + transId + reward + secret. Caller fills in everything else.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function signedPayload(array $fields): array
    {
        $sig = md5(($fields['subId'] ?? '').($fields['transId'] ?? '').($fields['reward'] ?? '').self::SECRET);

        return $fields + ['signature' => $sig];
    }
}
