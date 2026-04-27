<?php

namespace App\Console\Commands;

use App\Models\Withdrawal;
use App\Payout\ProcessWithdrawalJob;
use Illuminate\Console\Command;

class ProcessWithdrawalsCommand extends Command
{
    protected $signature = 'satpeek:process-withdrawals';

    protected $description = 'Dispatch queued withdrawals to FaucetPay.';

    public function handle(): int
    {
        $rows = Withdrawal::where('status', 'queued')->limit(100)->get();
        foreach ($rows as $w) {
            ProcessWithdrawalJob::dispatch($w->id);
        }
        $this->info("dispatched {$rows->count()} withdrawals");

        return self::SUCCESS;
    }
}
