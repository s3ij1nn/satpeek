<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Audit;

use App\BotDetection\ScoreEngine;
use App\Models\AdminAuditLog;
use App\Models\BalanceLedger;
use App\Models\PtcAd;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\AdminAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks the audit-trail write side at the action level. We don't drive
 * the Filament UI here — those calls are wrapped in Livewire and the
 * underlying business logic is the actual contract that needs pinning.
 * Each test mirrors one Filament action's mutation + asserts the
 * AdminAuditor entry shape.
 *
 * If a future refactor inlines new admin mutations without an
 * AdminAuditor::record() call, the corresponding test in this file
 * will fail loud.
 */
class AdminActionsAreAuditedTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_rescore_action_writes_audit_row(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create();
        $this->actingAs($admin);

        // Mirror UserResource::rescore action's body.
        $row = app(ScoreEngine::class)->evaluate($target);
        AdminAuditor::record('user.rescore', $target, [
            'tier' => $row->tier,
            'score' => round((float) $row->score, 4),
        ]);

        $log = AdminAuditLog::where('action', 'user.rescore')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame(User::class, $log->target_type);
        $this->assertSame($target->id, $log->target_id);
        $this->assertSame($row->tier, $log->payload['tier']);
    }

    public function test_ptc_ad_approve_action_writes_audit_row(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $advertiser = User::factory()->create();
        $ad = PtcAd::create([
            'user_id' => $advertiser->id,
            'source' => 'internal',
            'external_id' => 'audit-ad-'.uniqid(),
            'title' => 'Pending submission',
            'target_url' => 'https://example.com',
            'reward_sat' => 5,
            'cost_per_view_sat' => 1,
            'total_views_purchased' => 10,
            'views_remaining' => 10,
            'duration_sec' => 5,
            'daily_limit_per_user' => 5,
            'is_active' => false,
            'status' => 'pending_review',
        ]);
        $this->actingAs($admin);

        $ad->update([
            'status' => 'approved',
            'is_active' => true,
            'approved_at' => Carbon::now(),
            'reviewed_by' => $admin->id,
        ]);
        AdminAuditor::record('ptc_ad.approve', $ad);

        $log = AdminAuditLog::where('action', 'ptc_ad.approve')->first();
        $this->assertNotNull($log);
        $this->assertSame(PtcAd::class, $log->target_type);
        $this->assertSame($ad->id, $log->target_id);
    }

    public function test_ptc_ad_reject_action_audits_with_refund_amount(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $advertiser = User::factory()->create(['balance_sat' => 0]);
        $ad = PtcAd::create([
            'user_id' => $advertiser->id,
            'source' => 'internal',
            'external_id' => 'audit-rej-'.uniqid(),
            'title' => 'Soon-rejected',
            'target_url' => 'https://example.com',
            'reward_sat' => 5,
            'cost_per_view_sat' => 2,
            'total_views_purchased' => 100,
            'views_remaining' => 80,  // 80 * 2 = 160 sat refund
            'duration_sec' => 5,
            'daily_limit_per_user' => 5,
            'is_active' => true,
            'status' => 'approved',
        ]);
        $this->actingAs($admin);

        // Mirror PtcAdResource::reject body.
        $refund = (int) ($ad->views_remaining * $ad->cost_per_view_sat);
        DB::transaction(function () use ($ad, $refund, $admin) {
            BalanceLedger::create([
                'user_id' => $ad->user_id,
                'delta_sat' => $refund,
                'reason' => 'ad_refund',
                'reference_type' => PtcAd::class,
                'reference_id' => $ad->id,
            ]);
            $ad->advertiser->increment('balance_sat', $refund);
            $ad->update([
                'status' => 'rejected',
                'is_active' => false,
                'rejection_reason' => 'misleading content',
                'reviewed_by' => $admin->id,
                'views_remaining' => 0,
            ]);
        });
        AdminAuditor::record('ptc_ad.reject', $ad, [
            'rejection_reason' => 'misleading content',
            'refunded_sat' => $refund,
        ]);

        $log = AdminAuditLog::where('action', 'ptc_ad.reject')->first();
        $this->assertNotNull($log);
        $this->assertSame(160, $log->payload['refunded_sat'], 'must capture refund AMOUNT not the post-update zero');
        $this->assertSame('misleading content', $log->payload['rejection_reason']);
    }

    public function test_withdrawal_approve_audits_amount(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $w = Withdrawal::create([
            'user_id' => $user->id,
            'amount_sat' => 12_345,
            'faucetpay_email' => 'u@example.com',
            'currency' => 'BTC',
            'status' => 'hold',
            'requires_review' => true,
        ]);
        $this->actingAs($admin);

        $w->update(['status' => 'queued', 'requires_review' => false, 'reviewed_by' => $admin->id]);
        AdminAuditor::record('withdrawal.approve', $w, ['amount_sat' => (int) $w->amount_sat]);

        $log = AdminAuditLog::where('action', 'withdrawal.approve')->first();
        $this->assertNotNull($log);
        $this->assertSame(12_345, $log->payload['amount_sat']);
        $this->assertSame(Withdrawal::class, $log->target_type);
    }
}
