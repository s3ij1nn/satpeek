<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\BalanceLedgerResource;
use App\Models\BalanceLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the operator transaction-audit surface for `balance_ledgers`.
 *
 * Read-only by design — the ledger is the source of truth for
 * `users.balance_sat`. An admin write would either silently desync the
 * cached balance OR (worse, with a manual rebalance) hide a real
 * accounting bug. canCreate / canEdit / canDelete must all return false.
 */
class BalanceLedgerResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_contract(): void
    {
        $this->assertFalse(BalanceLedgerResource::canCreate());
        $this->assertFalse(BalanceLedgerResource::canEdit(null));
        $this->assertFalse(BalanceLedgerResource::canDelete(null));
    }

    public function test_non_admin_cannot_access_resource(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/admin/balance-ledgers');

        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
    }

    public function test_admin_can_list_ledger_with_signed_delta_rendering(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $u = User::factory()->create(['username' => 'alice_paid']);

        BalanceLedger::create([
            'user_id' => $u->id,
            'delta_sat' => 5000,
            'reason' => 'ptc_view',
        ]);
        BalanceLedger::create([
            'user_id' => $u->id,
            'delta_sat' => -2000,
            'reason' => 'withdraw_request',
        ]);

        $response = $this->actingAs($admin)->get('/admin/balance-ledgers');

        $response->assertOk();
        $response->assertSeeText('alice_paid');
        $response->assertSeeText('ptc_view');
        $response->assertSeeText('withdraw_request');
        // Signed Δ: positive → +5,000 (with leading +), negative → -2,000.
        $response->assertSee('+5,000', false);
        $response->assertSee('-2,000', false);
    }
}
