<?php

declare(strict_types=1);

namespace App\Payout\Tron;

use RuntimeException;

/**
 * Thrown by {@see TronHttpClient} when a Tron RPC call fails.
 *
 * Two flavours:
 *   - "all URLs unreachable" — TCP/DNS pre-flight failed on every
 *     configured RPC URL. Safe to retry; the request never reached
 *     the wire on any provider.
 *   - "http error" — at least one URL returned a non-2xx response. We
 *     do NOT retry these because we cannot tell whether the underlying
 *     operation was processed (especially relevant for broadcast).
 *
 * The Phase 2b TronGateway will distinguish these via separate
 * exception subclasses; for now the message text is enough for the
 * scaffolding.
 */
class TronRpcException extends RuntimeException {}
