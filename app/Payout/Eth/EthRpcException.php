<?php

declare(strict_types=1);

namespace App\Payout\Eth;

use RuntimeException;

/**
 * Thrown by {@see EthHttpClient} when an Ethereum JSON-RPC call
 * fails. Two flavours:
 *   - "all URLs unreachable" — TCP/DNS failure on every URL.
 *     Surfaces as {@see EthUnreachableException} so the caller
 *     can distinguish from non-retryable HTTP / RPC errors.
 *   - "rpc error" — at least one URL returned a non-2xx HTTP
 *     response or a JSON-RPC `error` field. We do NOT retry
 *     these because we cannot tell whether the underlying tx
 *     was mined (especially relevant for sendRawTransaction).
 */
class EthRpcException extends RuntimeException {}
