<?php

declare(strict_types=1);

namespace App\Payout\Btc;

use RuntimeException;

/**
 * Thrown by {@see BtcHttpClient} when a Bitcoin REST call fails.
 * Two flavours mirror the Tron / ETH / FaucetPay pattern:
 *   - "all URLs unreachable" → {@see BtcUnreachableException}
 *     (caller may retry; request never reached the wire)
 *   - "rest error" (HTTP 4xx/5xx, parse fail) → this class
 *     (terminal — broadcast may already have been processed)
 */
class BtcRpcException extends RuntimeException {}
