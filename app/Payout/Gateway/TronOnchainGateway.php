<?php

declare(strict_types=1);

namespace App\Payout\Gateway;

use App\Models\Withdrawal;
use App\Payout\Tron\TronAddress;
use App\Payout\Tron\TronHttpClient;
use App\Payout\Tron\TronRpcException;
use App\Payout\Tron\TronTxSigner;
use App\Payout\Tron\TronUnreachableException;

/**
 * Native-TRX onchain payout via TronGrid + simplito secp256k1 signing.
 *
 * Flow per `send()`:
 *   1. Validate destination Base58Check (defence-in-depth — the
 *      controller already rejects invalid addresses, but we re-check
 *      here so a directly-enqueued bad row can never broadcast).
 *   2. Build the unsigned tx via {@see TronHttpClient::createTransaction}
 *      (TronGrid returns `raw_data_hex` + `txID`).
 *   3. Sign `raw_data_hex` with {@see TronTxSigner} → 65-byte hex sig.
 *   4. Drop the signature into the envelope and broadcast via
 *      {@see TronHttpClient::broadcastTransaction}.
 *
 * Failure semantics (mirrors {@see FaucetPayGateway} → matches
 * `ProcessWithdrawalJob`'s retry policy):
 *   - {@see TronUnreachableException} bubbles → job retries (request
 *     never reached the wire on any RPC URL).
 *   - {@see TronRpcException} (HTTP error / contract revert / parse
 *     fail) → returns `PayoutResult::failed()` (terminal — could
 *     already be processed, mustn't retry).
 *   - Address invalid / amount zero / response missing required
 *     fields → returns `PayoutResult::failed()` directly.
 *
 * The gateway is registered in `AppServiceProvider` only when
 * `TRON_ONCHAIN_ENABLED=true` AND the hot-wallet env pair is present.
 * `WithdrawController` keys its allowed-methods list off
 * `PayoutGatewayRegistry::has()` so the validator + gateway stay in
 * lock-step.
 */
class TronOnchainGateway implements PayoutGateway
{
    public function __construct(
        private readonly TronHttpClient $http,
        private readonly TronTxSigner $signer,
        private readonly string $hotWalletAddress,
        private readonly string $hotWalletPrivateKey,
    ) {}

    public function name(): string
    {
        return Withdrawal::METHOD_ONCHAIN_TRX;
    }

    public function send(Withdrawal $withdrawal): PayoutResult
    {
        $destination = (string) $withdrawal->destination;
        if (! TronAddress::isValid($destination)) {
            return PayoutResult::failed(
                "invalid_destination: {$destination} is not a valid Tron address",
            );
        }

        // payout_amount is decimal(36,0) string for ETH-wei safety.
        // TRX amounts are in sun and fit easily in int64 — coerce here.
        $amountSun = (int) ($withdrawal->payout_amount ?? '0');
        if ($amountSun <= 0) {
            return PayoutResult::failed('amount_zero');
        }

        try {
            $unsigned = $this->http->createTransaction(
                fromAddress: $this->hotWalletAddress,
                toAddress: $destination,
                amountSun: $amountSun,
            );
        } catch (TronUnreachableException $e) {
            // Retry signal — re-throw so the job's retry machinery
            // re-enqueues with backoff.
            throw $e;
        } catch (TronRpcException $e) {
            return PayoutResult::failed("create_failed: {$e->getMessage()}");
        }

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

        // Tron broadcast envelope is the createtransaction response
        // verbatim plus a `signature: [hex]` array. visible=true was
        // set on create so we keep it.
        $envelope = $unsigned + ['signature' => [$signature]];

        try {
            $broadcast = $this->http->broadcastTransaction($envelope);
        } catch (TronUnreachableException $e) {
            throw $e;
        } catch (TronRpcException $e) {
            return PayoutResult::failed("broadcast_failed: {$e->getMessage()}");
        }

        // TronGrid encodes the failure message as hex bytes; decode for
        // operator-readable logs. `result=true` is the only success
        // signal — `code` is set on rejection (CONTRACT_VALIDATE_ERROR,
        // BANDWITH_ERROR, etc).
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
            message: 'tron broadcast accepted; awaiting confirmations',
            raw: $broadcast,
        );
    }
}
