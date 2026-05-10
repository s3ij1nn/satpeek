<?php

declare(strict_types=1);

namespace App\Payout\Gateway;

use App\Models\Withdrawal;
use App\Payout\Tron\TronAbi;
use App\Payout\Tron\TronAddress;
use App\Payout\Tron\TronHttpClient;
use App\Payout\Tron\TronRpcException;
use App\Payout\Tron\TronTxSigner;
use App\Payout\Tron\TronUnreachableException;

/**
 * USDT-TRC20 onchain payout — same chain as `TronOnchainGateway`, but
 * the transfer goes through the TRC20 contract's `transfer(address,
 * uint256)` function instead of a native sun transfer.
 *
 * Why a separate gateway (vs a flag on TronOnchainGateway)? Each chain-
 * asset pair is its own row in `PayoutGatewayRegistry` — that's how
 * `WithdrawController`'s allowed-methods derivation can list onchain_trx
 * + onchain_usdt_trc20 independently and how `ProcessWithdrawalJob`
 * dispatches without a chain-of-ifs. A future onchain_usdc_trc20 / per-
 * token gateway slots in here as another small class.
 *
 * Confirmation handling: TRC20 contract calls finalize at the same
 * 19-block Tron threshold as native transfers, BUT the contract can
 * REVERT (insufficient balance, paused contract, blacklisted recipient).
 * `WatchOnchainConfirmationsJob` handles the receipt.result check for
 * the TRC20 case — this gateway only owns the build / sign / broadcast
 * step.
 *
 * Fee limit: 100 TRX (100_000_000 sun) is the energy cap. A cold
 * recipient costs ~14 TRX worth of energy on USDT-TRC20; a warm one
 * costs ~5 TRX. 100 TRX leaves comfortable headroom for fee shocks.
 */
class TronUsdtTrc20Gateway implements PayoutGateway
{
    public function __construct(
        private readonly TronHttpClient $http,
        private readonly TronTxSigner $signer,
        private readonly string $hotWalletAddress,
        private readonly string $hotWalletPrivateKey,
        private readonly string $contractAddress,
    ) {}

    public function name(): string
    {
        return 'onchain_usdt_trc20';
    }

    public function send(Withdrawal $withdrawal): PayoutResult
    {
        $destination = (string) $withdrawal->destination;
        if (! TronAddress::isValid($destination)) {
            return PayoutResult::failed(
                "invalid_destination: {$destination} is not a valid Tron address",
            );
        }

        // USDT has 6 decimals — payout_amount is in the smallest unit
        // (e.g. 1 USDT = 1_000_000). decimal(36,0) string but USDT
        // values fit easily in int64 (max ≈ 9.2e18 USDT).
        $amount = (int) ($withdrawal->payout_amount ?? '0');
        if ($amount <= 0) {
            return PayoutResult::failed('amount_zero');
        }

        try {
            $parameter = TronAbi::encodeTransfer($destination, $amount);
        } catch (\InvalidArgumentException $e) {
            // toHash20 only throws on a malformed address; we already
            // gated on isValid above, so this is defence-in-depth.
            return PayoutResult::failed("encode_failed: {$e->getMessage()}");
        }

        try {
            $built = $this->http->triggerSmartContract(
                ownerAddress: $this->hotWalletAddress,
                contractAddress: $this->contractAddress,
                functionSelector: 'transfer(address,uint256)',
                parameter: $parameter,
            );
        } catch (TronUnreachableException $e) {
            throw $e;
        } catch (TronRpcException $e) {
            return PayoutResult::failed("create_failed: {$e->getMessage()}");
        }

        // triggersmartcontract returns the unsigned tx wrapped under
        // `transaction` (unlike createtransaction which returns it at
        // the top level). A failed build returns the error in `result`.
        if (! isset($built['transaction']) || ! is_array($built['transaction'])) {
            $code = (string) ($built['result']['code'] ?? 'unknown');
            $message = (string) ($built['result']['message'] ?? '');

            return PayoutResult::failed(
                "create_failed: {$code} {$message}",
                $built,
            );
        }
        $unsigned = $built['transaction'];

        if (! isset($unsigned['raw_data_hex'], $unsigned['txID'])) {
            return PayoutResult::failed(
                'create_failed: response missing raw_data_hex or txID',
                $unsigned,
            );
        }

        $signature = $this->signer->sign(
            rawDataHex: (string) $unsigned['raw_data_hex'],
            privateKeyHex: $this->hotWalletPrivateKey,
        );

        $envelope = $unsigned + ['signature' => [$signature]];

        try {
            $broadcast = $this->http->broadcastTransaction($envelope);
        } catch (TronUnreachableException $e) {
            throw $e;
        } catch (TronRpcException $e) {
            return PayoutResult::failed("broadcast_failed: {$e->getMessage()}");
        }

        if (($broadcast['result'] ?? false) !== true) {
            $code = (string) ($broadcast['code'] ?? 'unknown');
            $rawMessage = (string) ($broadcast['message'] ?? '');
            $decoded = ctype_xdigit($rawMessage)
                ? (string) @hex2bin($rawMessage)
                : $rawMessage;

            return PayoutResult::failed(
                "broadcast_rejected: {$code} {$decoded}",
                $broadcast,
            );
        }

        return PayoutResult::sent(
            externalId: (string) $unsigned['txID'],
            message: 'usdt-trc20 broadcast accepted; awaiting confirmations + receipt',
            raw: $broadcast,
        );
    }
}
