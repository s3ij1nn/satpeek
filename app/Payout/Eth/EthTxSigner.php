<?php

declare(strict_types=1);

namespace App\Payout\Eth;

use Elliptic\EC;
use InvalidArgumentException;
use kornrunner\Keccak;

/**
 * Builds + signs an EIP-1559 (type-2) Ethereum transaction.
 *
 * Why type-2 (not legacy / type-0)? The London hard fork (Aug 2021)
 * made EIP-1559 the dominant tx shape; every modern wallet emits
 * type-2. Legacy `gasPrice` is still accepted by every node but
 * pays the burn-rate inefficiently — type-2 lets us cap maxFee
 * separately from the tip the miner takes, which matters for a
 * payout cron that doesn't want to over-pay during fee spikes.
 *
 * Signing flow (per EIP-1559):
 *   1. Build the unsigned RLP payload =
 *      RLP([chainId, nonce, maxPriority, maxFee, gasLimit, to, value, data, []])
 *   2. Signing hash = keccak256(0x02 || RLP_payload)
 *   3. ECDSA-sign the hash (secp256k1, canonical low-s, RFC6979 k)
 *   4. Append (yParity, r, s) inside the same RLP list
 *   5. Final raw tx = 0x02 || RLP(signed-list), then 0x-hex it
 *
 * yParity is the recovery param's lowest bit (0 or 1) — NOT
 * Ethereum-classic's `v = 27 + recovery`. EIP-2930 + EIP-1559
 * dropped the +27 convention for typed transactions.
 */
class EthTxSigner
{
    private readonly EC $ec;

    public function __construct()
    {
        $this->ec = new EC('secp256k1');
    }

    /**
     * Build + sign + hex-encode a type-2 ETH transfer transaction.
     * Returns the `0x...` raw hex ready for `eth_sendRawTransaction`.
     *
     * `$tx` is an associative array with these keys (all in
     * smallest-unit decimal STRINGS where applicable, since wei ×
     * multi-ETH overflows int64):
     *   - chainId               — string decimal (1 = mainnet)
     *   - nonce                 — int
     *   - maxPriorityFeePerGas  — string decimal wei
     *   - maxFeePerGas          — string decimal wei
     *   - gasLimit              — int (typically 21000 for ETH transfer)
     *   - to                    — EIP-55-validated address string
     *   - value                 — string decimal wei
     *   - data                  — hex (no 0x), '' for plain transfer
     *
     * @param  array<string, mixed>  $tx
     * @param  string  $privateKeyHex  64-char hex (no 0x prefix)
     */
    public function signEip1559(array $tx, string $privateKeyHex): string
    {
        if (! ctype_xdigit($privateKeyHex) || strlen($privateKeyHex) !== 64) {
            throw new InvalidArgumentException('privateKeyHex must be exactly 64 hex chars');
        }

        $toBytes = EthAddress::toBytes((string) $tx['to']);
        $dataBytes = $this->hexToBytes((string) $tx['data']);

        // Unsigned RLP payload — order is fixed by EIP-1559 spec.
        $unsignedRlp = EthRlp::encodeList([
            EthRlp::encodeUint((string) $tx['chainId']),
            EthRlp::encodeUint((string) $tx['nonce']),
            EthRlp::encodeUint((string) $tx['maxPriorityFeePerGas']),
            EthRlp::encodeUint((string) $tx['maxFeePerGas']),
            EthRlp::encodeUint((string) $tx['gasLimit']),
            EthRlp::encodeBytes($toBytes),
            EthRlp::encodeUint((string) $tx['value']),
            EthRlp::encodeBytes($dataBytes),
            EthRlp::encodeList([]), // empty access list
        ]);

        // EIP-2718 envelope: type byte 0x02 prepended BEFORE the
        // signing hash. The node strips the 0x02 to reconstruct
        // the same hash on its side.
        $signingHash = Keccak::hash("\x02".$unsignedRlp, 256);

        $key = $this->ec->keyFromPrivate($privateKeyHex, 'hex');
        $sig = $key->sign($signingHash, ['canonical' => true]);

        $r = str_pad($sig->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad($sig->s->toString(16), 64, '0', STR_PAD_LEFT);
        // yParity: 0 or 1, from the recovery param's lowest bit.
        // EIP-1559 uses raw 0/1 — NOT Ethereum-classic's 27 + recovery.
        $yParity = ($sig->recoveryParam ?? 0) & 1;

        $signedRlp = EthRlp::encodeList([
            EthRlp::encodeUint((string) $tx['chainId']),
            EthRlp::encodeUint((string) $tx['nonce']),
            EthRlp::encodeUint((string) $tx['maxPriorityFeePerGas']),
            EthRlp::encodeUint((string) $tx['maxFeePerGas']),
            EthRlp::encodeUint((string) $tx['gasLimit']),
            EthRlp::encodeBytes($toBytes),
            EthRlp::encodeUint((string) $tx['value']),
            EthRlp::encodeBytes($dataBytes),
            EthRlp::encodeList([]),
            EthRlp::encodeUint((string) $yParity),
            EthRlp::encodeBytes($this->stripLeadingZeros((string) hex2bin($r))),
            EthRlp::encodeBytes($this->stripLeadingZeros((string) hex2bin($s))),
        ]);

        return '0x02'.bin2hex($signedRlp);
    }

    /**
     * Compute the txHash of a signed raw tx — keccak256 of the
     * full envelope including the type byte. Useful for callers
     * that want the txid before broadcasting (e.g. logging).
     */
    public function computeTxHash(string $rawHex): string
    {
        $bytes = (string) hex2bin(self::strip0x($rawHex));

        return '0x'.Keccak::hash($bytes, 256);
    }

    private function hexToBytes(string $hex): string
    {
        $hex = self::strip0x($hex);
        if ($hex === '') {
            return '';
        }
        if (! ctype_xdigit($hex) || strlen($hex) % 2 !== 0) {
            throw new InvalidArgumentException("data must be even-length hex, got: {$hex}");
        }

        return (string) hex2bin($hex);
    }

    private function stripLeadingZeros(string $bytes): string
    {
        $i = 0;
        while ($i < strlen($bytes) - 1 && $bytes[$i] === "\x00") {
            $i++;
        }

        return substr($bytes, $i);
    }

    private static function strip0x(string $hex): string
    {
        return str_starts_with($hex, '0x') || str_starts_with($hex, '0X') ? substr($hex, 2) : $hex;
    }
}
