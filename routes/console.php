<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('satpeek:sync-offerwalls')->everyFifteenMinutes();
Schedule::command('satpeek:process-withdrawals')->everyMinute();

// Nightly housekeeping: expire stale issued captcha challenges + prune
// resolved rows older than 30 days. 03:00 UTC is the global low-traffic
// trough and lands fresh signal in the morning-PST standup window.
Schedule::command('satpeek:cleanup-captcha')->dailyAt('03:00');
