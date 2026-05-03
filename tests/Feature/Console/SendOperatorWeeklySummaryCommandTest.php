<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Mail\OperatorWeeklySummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Locks the satpeek:weekly-summary command behaviour:
 *
 *   - sends ONE OperatorWeeklySummary per admin user
 *   - skips non-admins
 *   - --dry-run prints the JSON payload but queues nothing
 *   - exits 0 with a friendly warning when no admins exist
 *     (so the cron doesn't paint the schedule output red on a
 *     fresh install before the first admin is seeded)
 */
class SendOperatorWeeklySummaryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_one_mail_per_admin(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true, 'email' => 'a@example.com']);
        User::factory()->create(['is_admin' => true, 'email' => 'b@example.com']);
        User::factory()->create(['is_admin' => false, 'email' => 'c@example.com']);

        $this->artisan('satpeek:weekly-summary')->assertExitCode(0);

        Mail::assertQueued(OperatorWeeklySummary::class, 2);
        Mail::assertQueued(OperatorWeeklySummary::class, fn ($mail) => $mail->hasTo('a@example.com'));
        Mail::assertQueued(OperatorWeeklySummary::class, fn ($mail) => $mail->hasTo('b@example.com'));
        Mail::assertNotQueued(OperatorWeeklySummary::class, fn ($mail) => $mail->hasTo('c@example.com'));
    }

    public function test_dry_run_does_not_dispatch_any_mail(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true, 'email' => 'a@example.com']);

        $this->artisan('satpeek:weekly-summary', ['--dry-run' => true])
            ->assertExitCode(0);

        Mail::assertNothingQueued();
    }

    public function test_no_admins_exits_clean_with_warning(): void
    {
        Mail::fake();

        $this->artisan('satpeek:weekly-summary')
            ->expectsOutputToContain('No admin users found')
            ->assertExitCode(0);

        Mail::assertNothingQueued();
    }
}
