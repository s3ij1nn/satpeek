<?php

declare(strict_types=1);

namespace App\Payout\Eth;

use App\Payout\FaucetPayUnreachableException;
use App\Payout\Tron\TronUnreachableException;

/**
 * Subclass of {@see EthRpcException} signalling "every configured
 * RPC URL was TCP/DNS unreachable — the request never reached the
 * wire on any provider".
 *
 * `ProcessWithdrawalJob` lets this exception escape so Laravel's
 * retry machinery re-enqueues with backoff. Mirrors the
 * {@see TronUnreachableException} +
 * {@see FaucetPayUnreachableException} retry contract.
 */
class EthUnreachableException extends EthRpcException {}
