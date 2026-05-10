<?php

declare(strict_types=1);

namespace App\Payout\Tron;

/**
 * Subclass of {@see TronRpcException} that signals "every configured
 * RPC URL was TCP/DNS unreachable — the request never reached the
 * wire on any provider".
 *
 * `ProcessWithdrawalJob` lets this exception escape so Laravel's retry
 * machinery re-enqueues the job with backoff (mirrors the
 * `FaucetPayUnreachableException` semantics for the FP route). HTTP-
 * level errors (4xx / 5xx, timeouts mid-request) are surfaced as the
 * plain `TronRpcException` and become terminal failures because the
 * underlying broadcast might have been processed.
 */
class TronUnreachableException extends TronRpcException {}
