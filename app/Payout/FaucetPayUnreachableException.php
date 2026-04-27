<?php

declare(strict_types=1);

namespace App\Payout;

use RuntimeException;

/**
 * Thrown by {@see FaucetPayClient} when the FaucetPay API host could not
 * be reached at the TCP / DNS layer — i.e., the request never made it onto
 * the wire. Distinct from a "FaucetPay returned a non-200" failure because
 * it is the ONLY case where SatPeek can safely retry without risking a
 * duplicate payout: if the server never saw the request, it never started
 * processing the payout.
 *
 * Anything else (timeout mid-request, HTTP 5xx response, body status != 200,
 * JSON parse error) is treated by the caller as a permanent / ambiguous
 * failure and pushed straight to dead-letter for operator review.
 */
class FaucetPayUnreachableException extends RuntimeException {}
