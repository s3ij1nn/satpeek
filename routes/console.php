<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('satpeek:sync-offerwalls')->everyFifteenMinutes();
Schedule::command('satpeek:process-withdrawals')->everyMinute();
