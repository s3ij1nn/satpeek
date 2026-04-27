<?php

namespace Tests\Feature\Console;

use App\Models\CaptchaChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CleanupCaptchaChallengesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expires_issued_rows_past_their_expires_at(): void
    {
        $expired = $this->seedChallenge([
            'status' => 'issued',
            'issued_at' => Carbon::now()->subHours(2),
            'expires_at' => Carbon::now()->subHour(),
        ]);
        $stillFresh = $this->seedChallenge([
            'status' => 'issued',
            'issued_at' => Carbon::now()->subMinute(),
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $this->artisan('satpeek:cleanup-captcha')->assertSuccessful();

        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertSame('ttl_exceeded_by_cleanup', $expired->fresh()->rejection_reason);
        $this->assertNotNull($expired->fresh()->resolved_at);
        // Fresh row must not be touched.
        $this->assertSame('issued', $stillFresh->fresh()->status);
    }

    public function test_prunes_resolved_rows_older_than_cutoff(): void
    {
        // updated_at is what the prune query inspects — Eloquent stamps it
        // on every save, so we backdate via a direct update for the past
        // case and trust the seed timestamp for the recent one.
        $oldVerified = $this->seedChallenge(['status' => 'verified']);
        $oldRejected = $this->seedChallenge(['status' => 'rejected']);
        $oldExpired = $this->seedChallenge(['status' => 'expired']);
        $recentVerified = $this->seedChallenge(['status' => 'verified']);
        $stillIssued = $this->seedChallenge(['status' => 'issued', 'expires_at' => Carbon::now()->addMinute()]);

        CaptchaChallenge::whereIn('id', [$oldVerified->id, $oldRejected->id, $oldExpired->id])
            ->update(['updated_at' => Carbon::now()->subDays(31)]);

        $this->artisan('satpeek:cleanup-captcha')->assertSuccessful();

        $this->assertNull(CaptchaChallenge::find($oldVerified->id));
        $this->assertNull(CaptchaChallenge::find($oldRejected->id));
        $this->assertNull(CaptchaChallenge::find($oldExpired->id));
        // Recent + still-pending rows must survive.
        $this->assertNotNull(CaptchaChallenge::find($recentVerified->id));
        $this->assertNotNull(CaptchaChallenge::find($stillIssued->id));
    }

    public function test_dry_run_does_not_mutate(): void
    {
        $expired = $this->seedChallenge([
            'status' => 'issued',
            'issued_at' => Carbon::now()->subHour(),
            'expires_at' => Carbon::now()->subMinutes(30),
        ]);
        $oldVerified = $this->seedChallenge(['status' => 'verified']);
        CaptchaChallenge::where('id', $oldVerified->id)
            ->update(['updated_at' => Carbon::now()->subDays(60)]);

        $this->artisan('satpeek:cleanup-captcha', ['--dry-run' => true])
            ->expectsOutputToContain('would expire 1')
            ->expectsOutputToContain('would prune 1')
            ->assertSuccessful();

        // Both rows still in their original state.
        $this->assertSame('issued', $expired->fresh()->status);
        $this->assertNotNull(CaptchaChallenge::find($oldVerified->id));
    }

    public function test_custom_days_option_changes_cutoff(): void
    {
        $sevenDaysOld = $this->seedChallenge(['status' => 'verified']);
        CaptchaChallenge::where('id', $sevenDaysOld->id)
            ->update(['updated_at' => Carbon::now()->subDays(7)]);

        // Default 30-day cutoff would keep this row.
        $this->artisan('satpeek:cleanup-captcha')->assertSuccessful();
        $this->assertNotNull(CaptchaChallenge::find($sevenDaysOld->id));

        // Tightened to 5 days, the row gets pruned.
        $this->artisan('satpeek:cleanup-captcha', ['--days' => 5])->assertSuccessful();
        $this->assertNull(CaptchaChallenge::find($sevenDaysOld->id));
    }

    private function seedChallenge(array $overrides = []): CaptchaChallenge
    {
        return CaptchaChallenge::create(array_merge([
            'challenge_id' => 'cc_'.uniqid(),
            'user_id' => null,
            'session_id' => 'test',
            'provider' => 'trajectory_trace',
            'seed' => 'test-seed',
            'expected_shape' => [['x' => 0, 'y' => 0, 't' => 0]],
            'fingerprint_hash' => null,
            'client_ip' => '127.0.0.1',
            'ja4' => null,
            'user_agent' => 'phpunit',
            'status' => 'issued',
            'issued_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(5),
        ], $overrides));
    }
}
