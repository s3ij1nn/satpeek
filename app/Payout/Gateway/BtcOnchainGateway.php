<?php

declare(strict_types=1);

namespace App\Payout\Gateway;

use App\Models\Withdrawal;
use App\Payout\Btc\BtcAddress;
use App\Payout\Btc\BtcHttpClient;
use App\Payout\Btc\BtcRpcException;
use App\Payout\Btc\BtcTxSigner;
use App\Payout\Btc\BtcUnreachableException;
use App\Payout\Btc\BtcUtxoSelector;

/**
 * Native BTC onchain payout via mempool.space + BIP143 P2WPKH
 * segwit signing.
 *
 * Flow per `send()`:
 *   1. Validate destination as bech32 P2WPKH (HRP `bc` mainnet).
 *   2. Pull the hot-wallet's confirmed UTXOs and the recommended
 *      fee rate from mempool.space.
 *   3. {@see BtcUtxoSelector::select} picks UTXOs largest-first
 *      until they cover `amount + estimated fee`. Dust change
 *      accrues to the miner fee.
 *   4. Build + sign the segwit tx via {@see BtcTxSigner::signSegwit}
 *      with each input attached to the hot-wallet privkey.
 *   5. Broadcast via mempool.space `POST /tx`. Returned txid is
 *      the externalId on the PayoutResult.
 *
 * Failure semantics — mirror Tron / ETH / FaucetPay:
 *   - {@see BtcUnreachableException} bubbles → job retries.
 *   - {@see BtcRpcException} (HTTP error) or insufficient UTXOs →
 *     `PayoutResult::failed()` (terminal).
 *   - Address invalid / amount zero → returns failed directly,
 *     no UTXO read, no broadcast.
 *
 * Hot wallet: BTC uses a UTXO model. The operator's bech32 address
 * MUST hold the spendable UTXOs (the gateway doesn't manage
 * multiple keys / HD derivation in v1; one address = one privkey).
 * Periodic consolidation is the operator's responsibility.
 */
class BtcOnchainGateway implements PayoutGateway
{
    public function __construct(
        private readonly BtcHttpClient $http,
        private readonly BtcTxSigner $signer,
        private readonly BtcUtxoSelector $selector,
        private readonly string $hotWalletAddress,
        private readonly string $hotWalletPrivateKey,
    ) {}

    public function name(): string
    {
        return Withdrawal::METHOD_ONCHAIN_BTC;
    }

    public function send(Withdrawal $withdrawal): PayoutResult
    {
        $destination = (string) $withdrawal->destination;
        if (! BtcAddress::isValid($destination)) {
            return PayoutResult::failed(
                "invalid_destination: {$destination} is not a valid bech32 P2WPKH address",
            );
        }

        $amountSat = (int) ($withdrawal->payout_amount ?? 0);
        if ($amountSat <= 0) {
            return PayoutResult::failed('amount_zero');
        }

        try {
            $utxos = $this->http->addressUtxos($this->hotWalletAddress);
            $fees = $this->http->feeRecommended();
        } catch (BtcUnreachableException $e) {
            throw $e; // safe to retry — read-side failure, no broadcast yet
        } catch (BtcRpcException $e) {
            return PayoutResult::failed("state_read_failed: {$e->getMessage()}");
        }
        // hourFee is the conservative-payout default; can be overridden
        // per-deploy via env if a deploy needs faster confirmations.
        $feeRate = (int) ($fees['hourFee'] ?? $fees['halfHourFee'] ?? 1);
        if ($feeRate < 1) {
            $feeRate = 1;
        }

        try {
            $selection = $this->selector->select($utxos, $amountSat, $feeRate);
        } catch (\RuntimeException $e) {
            return PayoutResult::failed("utxo_selection_failed: {$e->getMessage()}");
        }

        // Attach the hot-wallet privkey to every input (single-address
        // wallet — every UTXO is spendable by the same key).
        $inputs = array_map(
            fn (array $in): array => $in + ['privKeyHex' => $this->hotWalletPrivateKey],
            $selection['inputs'],
        );

        // Build outputs: recipient + (optional) change back to hot wallet.
        $outputs = [
            ['scriptPubKey' => BtcAddress::toScriptPubKey($destination), 'value' => $amountSat],
        ];
        if ($selection['change'] > 0) {
            $outputs[] = [
                'scriptPubKey' => BtcAddress::toScriptPubKey($this->hotWalletAddress),
                'value' => $selection['change'],
            ];
        }

        try {
            $rawHex = $this->signer->signSegwit($inputs, $outputs);
        } catch (\Throwable $e) {
            return PayoutResult::failed("sign_failed: {$e->getMessage()}");
        }

        try {
            $txid = $this->http->broadcast($rawHex);
        } catch (BtcUnreachableException $e) {
            throw $e;
        } catch (BtcRpcException $e) {
            return PayoutResult::failed("broadcast_failed: {$e->getMessage()}");
        }

        return PayoutResult::sent(
            externalId: $txid,
            message: 'btc broadcast accepted; awaiting confirmations',
            raw: ['raw_tx' => $rawHex, 'fee_sat' => $selection['fee']],
        );
    }
}
