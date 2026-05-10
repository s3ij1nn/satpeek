<?php

declare(strict_types=1);

namespace App\Payout;

use RuntimeException;

/**
 * Thrown by {@see WalletBalanceMonitor::available()} when the
 * transport (FaucetPay API, RPC node) cannot be reached.
 *
 * Treated as "unknown" by the Filament widget + `/up` probe (shows
 * a "balance check failed" indicator instead of pretending the wallet
 * is empty). Callers MUST NOT trust a fallback zero — that would
 * trigger spurious "hot wallet drained" alerts.
 */
class WalletBalanceUnavailableException extends RuntimeException {}
