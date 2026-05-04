<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('satpeek:sync-offerwalls')->everyFifteenMinutes();
Schedule::command('satpeek:process-withdrawals')->everyMinute();

// Nightly housekeeping: expire stale issued captcha challenges + prune
// resolved rows older than 30 days. 03:00 UTC is the global low-traffic
// trough and lands fresh signal in the morning-PST standup window.
Schedule::command('satpeek:cleanup-captcha')->dailyAt('03:00');

// Prune bot_score_history older than the retention window (default 90 d).
// Staggered 15 min after the captcha sweep so the two heavy DELETEs don't
// share an IO window — same low-traffic trough, no contention.
Schedule::command('satpeek:prune-bot-score-history')->dailyAt('03:15');

// Operator weekly summary email — past-7-days digest of earning
// activity, payouts, new users, and bot tier evaluations. Mondays
// 09:00 UTC catches PST early-morning standup + EU lunch + JP
// late-evening review windows. See app/Mail/OperatorWeeklySummary.
Schedule::command('satpeek:weekly-summary')->weeklyOn(1, '09:00');
