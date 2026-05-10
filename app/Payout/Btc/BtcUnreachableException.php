<?php

declare(strict_types=1);

namespace App\Payout\Btc;

/**
 * Subclass of {@see BtcRpcException} signalling "every configured
 * mempool.space / esplora URL was TCP/DNS unreachable — the request
 * never reached the wire on any provider".
 *
 * Same retry contract as the Tron / ETH / FaucetPay equivalents:
 * `ProcessWithdrawalJob` lets this exception escape so Laravel's
 * retry machinery re-enqueues with backoff.
 */
class BtcUnreachableException extends BtcRpcException {}
