<?php

namespace App\Console\Commands;

use App\Mail\OperatorWeeklySummary;
use App\Models\User;
use App\Services\WeeklySummaryBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Builds last-7-days activity summary and emails it to every admin user.
 *
 * Scheduled weekly on Mondays at 09:00 UTC (see routes/console.php).
 * Operators who don't habitually open `/admin` still get the signal
 * they need to react to spikes in tier escalations or payout anomalies.
 *
 * `--dry-run` prints the JSON payload without queuing any mail —
 * handy for verifying the buckets locally without an SMTP setup.
 */
class SendOperatorWeeklySummaryCommand extends Command
{
    protected $signature = 'satpeek:weekly-summary {--dry-run : Print the payload without sending mail}';

    protected $description = 'Send the past-7-days platform summary to admin users.';

    public function handle(WeeklySummaryBuilder $builder): int
    {
        $payload = $builder->build();

        if ($this->option('dry-run')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $admins = User::query()
            ->where('is_admin', true)
            ->whereNotNull('email')
            ->get();

        if ($admins->isEmpty()) {
            $this->warn('No admin users found — nothing sent.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->queue(new OperatorWeeklySummary($payload));
                $sent++;
            } catch (\Throwable $e) {
                $this->warn("Mail to {$admin->email} failed: {$e->getMessage()}");
            }
        }

        $this->info("Queued weekly summary to {$sent} admin user(s).");

        return self::SUCCESS;
    }
}
