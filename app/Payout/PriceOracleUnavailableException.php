<?php

declare(strict_types=1);

namespace App\Payout;

use RuntimeException;

/**
 * Thrown by {@see PriceOracle::convertBtcSatToTarget()} when no rate
 * is available — typically CoinGecko unreachable AND no warm cache.
 *
 * The withdrawal flow catches this and surfaces a 503-style error to
 * the user rather than guessing a rate. The user retries once the
 * oracle recovers; balance has not been debited yet at the point this
 * fires (rate is computed before the DB transaction opens).
 */
class PriceOracleUnavailableException extends RuntimeException {}
