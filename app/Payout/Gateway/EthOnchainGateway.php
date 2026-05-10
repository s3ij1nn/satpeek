<?php

declare(strict_types=1);

namespace App\Payout\Gateway;

use App\Models\Withdrawal;
use App\Payout\Eth\EthAddress;
use App\Payout\Eth\EthHttpClient;
use App\Payout\Eth\EthRpcException;
use App\Payout\Eth\EthTxSigner;
use App\Payout\Eth\EthUnreachableException;

/**
 * Native-ETH onchain payout via JSON-RPC + EIP-1559 signing.
 *
 * Flow per `send()`:
 *   1. Validate destination as EIP-55 / lowercase / uppercase hex.
 *   2. Read chain state: chainId, sender nonce (`eth_getTransactionCount`
 *      pending), fee oracle (`eth_feeHistory` 5 blocks @ 50th
 *      percentile).
 *   3. Compute fee caps: priorityFee = median of recent tips,
 *      maxFee = baseFee × 2 + priorityFee (1-block headroom for
 *      base fee bumps without over-paying).
 *   4. Build + sign a type-2 tx via {@see EthTxSigner::signEip1559}.
 *   5. Broadcast via `eth_sendRawTransaction`. Returned txHash is
 *      the externalId on the PayoutResult.
 *
 * Failure semantics — same as Tron / FaucetPay:
 *   - {@see EthUnreachableException} bubbles → job retries.
 *   - {@see EthRpcException} (HTTP error / RPC error / parse fail)
 *     → returns `PayoutResult::failed()` (terminal).
 *   - Address invalid / amount zero / state-read failure → returns
 *     failed directly without broadcasting.
 *
 * Hot wallet: ETH uses an account model (no UTXO management). The
 * private key signs every outgoing tx; the nonce auto-increments
 * via `eth_getTransactionCount` (pending tag includes mempool tx
 * so concurrent broadcasts don't collide).
 */
class EthOnchainGateway implements PayoutGateway
{
    /** Plain ETH transfer always costs exactly 21,000 gas — protocol-fixed. */
    private const TRANSFER_GAS_LIMIT = 21_000;

    /**
     * Floor for the priority fee (tip) when the oracle returns 0.
     * A zero tip lands the tx in the slow lane; 1 gwei keeps the
     * payout cron predictable without overpaying.
     */
    private const PRIORITY_FEE_FLOOR_WEI = '1000000000'; // 1 gwei

    public function __construct(
        private readonly EthHttpClient $http,
        private readonly EthTxSigner $signer,
        private readonly string $hotWalletAddress,
        private readonly string $hotWalletPrivateKey,
    ) {}

    public function name(): string
    {
        return Withdrawal::METHOD_ONCHAIN_ETH;
    }

    public function send(Withdrawal $withdrawal): PayoutResult
    {
        $destination = (string) $withdrawal->destination;
        if (! EthAddress::isValid($destination)) {
            return PayoutResult::failed(
                "invalid_destination: {$destination} is not a valid Ethereum address",
            );
        }

        $valueWei = (string) ($withdrawal->payout_amount ?? '0');
        if (! ctype_digit($valueWei) || $valueWei === '0') {
            return PayoutResult::failed('amount_zero');
        }

        try {
            $chainId = $this->http->chainId();
            $nonce = $this->http->getTransactionCount($this->hotWalletAddress);
            $fees = $this->computeFees();
        } catch (EthUnreachableException $e) {
            // Read-side failure with no broadcast yet — safe to retry.
            throw $e;
        } catch (EthRpcException $e) {
            return PayoutResult::failed("state_read_failed: {$e->getMessage()}");
        }

        $rawHex = $this->signer->signEip1559([
            'chainId' => (string) $chainId,
            'nonce' => $nonce,
            'maxPriorityFeePerGas' => $fees['priority'],
            'maxFeePerGas' => $fees['max'],
            'gasLimit' => self::TRANSFER_GAS_LIMIT,
            'to' => $destination,
            'value' => $valueWei,
            'data' => '',
        ], $this->hotWalletPrivateKey);

        $expectedTxHash = $this->signer->computeTxHash($rawHex);

        try {
            $broadcastTxHash = $this->http->sendRawTransaction($rawHex);
        } catch (EthUnreachableException $e) {
            throw $e;
        } catch (EthRpcException $e) {
            return PayoutResult::failed("broadcast_failed: {$e->getMessage()}");
        }

        return PayoutResult::sent(
            externalId: $broadcastTxHash !== '' ? $broadcastTxHash : $expectedTxHash,
            message: 'eth broadcast accepted; awaiting confirmations',
            raw: ['raw_tx' => $rawHex, 'tx_hash' => $broadcastTxHash],
        );
    }

    /**
     * Pull fee history + derive maxPriorityFee + maxFee.
     *
     * @return array{priority: string, max: string}
     */
    private function computeFees(): array
    {
        $hist = $this->http->feeHistory(5, 50);
        // baseFeePerGas in feeHistory has length blockCount+1 — last
        // entry is the predicted base fee for the NEXT block.
        $baseFeeHexes = (array) ($hist['baseFeePerGas'] ?? []);
        $nextBaseHex = (string) end($baseFeeHexes);
        $baseFeeWei = $nextBaseHex !== ''
            ? gmp_strval(gmp_init(self::strip0x($nextBaseHex), 16), 10)
            : '0';

        // reward[block][percentile] — pick median across the window.
        $tips = [];
        foreach ((array) ($hist['reward'] ?? []) as $blockRewards) {
            $tipHex = is_array($blockRewards) ? (string) ($blockRewards[0] ?? '0x0') : '0x0';
            $tipHex = self::strip0x($tipHex);
            $tips[] = $tipHex !== '' ? gmp_strval(gmp_init($tipHex, 16), 10) : '0';
        }
        $priorityWei = $this->medianDecimal($tips);
        // Floor — a zero-tip tx lands in the slow lane.
        if (bccomp($priorityWei, self::PRIORITY_FEE_FLOOR_WEI, 0) < 0) {
            $priorityWei = self::PRIORITY_FEE_FLOOR_WEI;
        }

        // maxFee = baseFee × 2 + priorityFee. The ×2 is the standard
        // 1-block headroom (base fee can grow at most 12.5% per block
        // under EIP-1559, so 2× covers ~6 blocks of fee growth).
        $maxFeeWei = bcadd(bcmul($baseFeeWei, '2', 0), $priorityWei, 0);

        return ['priority' => $priorityWei, 'max' => $maxFeeWei];
    }

    /**
     * @param  array<int, string>  $values  decimal strings
     */
    private function medianDecimal(array $values): string
    {
        if ($values === []) {
            return '0';
        }
        usort($values, fn (string $a, string $b): int => bccomp($a, $b, 0));
        $mid = intdiv(count($values), 2);

        return $values[$mid];
    }

    private static function strip0x(string $hex): string
    {
        return str_starts_with($hex, '0x') || str_starts_with($hex, '0X') ? substr($hex, 2) : $hex;
    }
}
