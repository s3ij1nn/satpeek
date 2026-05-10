<?php

declare(strict_types=1);

namespace App\Deposit;

use RuntimeException;

/**
 * Thrown by {@see DepositObserver::scan()} on transport failure
 * (RPC unreachable, indexer outage, malformed response).
 *
 * The caller (future `WatchDepositsJob`) catches and logs at
 * warning, then retries on the next cron tick — observation is
 * idempotent so a missed tick doesn't drop deposits, just delays
 * their detection.
 */
class DepositObserverException extends RuntimeException {}
