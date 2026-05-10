<?php

declare(strict_types=1);

namespace App\Payout\Tron;

use Elliptic\EC;
use InvalidArgumentException;

/**
 * Produces a Tron-broadcast-shaped secp256k1 ECDSA signature for a
 * transaction's `raw_data_hex` payload. Pure crypto — no Laravel,
 * no HTTP, no state.
 *
 * Tron's signing flow:
 *   1. The node returns `raw_data` + `raw_data_hex` from
 *      `/wallet/createtransaction` (or `/wallet/triggersmartcontract`
 *      for TRC20 contract calls).
 *   2. The signer SHA256s the raw_data bytes → 32-byte digest.
 *   3. ECDSA-signs the digest under secp256k1 using RFC6979
 *      deterministic-k (no entropy required, same input → same
 *      output, safe under retry).
 *   4. Encodes the signature as `r (32B) || s (32B) || v (1B)` hex
 *      and the caller adds it to the broadcast envelope's
 *      `signature: [...]` array.
 *
 * v (recovery param) ranges over {0, 1} for secp256k1 + canonical
 * signatures — see `TronTxSignerTest::test_recovery_param_is_0_or_1`
 * for the assumption pin. Tron expects raw 0/1, NOT Ethereum's 27/28.
 *
 * Why simplito/elliptic-php (not iexbase/tron-api or fenguoz/tron-php)?
 * The two Tron-specific PHP libraries are PHP 8.0-only and have
 * been unmaintained for 18+ months. simplito is actively maintained,
 * pure-PHP-via-bn-php (no native ext beyond gmp), passes the
 * upstream `elliptic` JS test vectors, and is the same library that
 * powers PrivMX WebMail in production. The Tron-specific glue here
 * is small enough to own (signing + tx-build helpers) without
 * dragging in an abandoned wrapper.
 */
final class TronTxSigner
{
    private readonly EC $ec;

    public function __construct()
    {
        // simplito's docs recommend reusing one EC context; we let
        // Laravel's container singleton this class so the curve
        // initialisation cost is paid once.
        $this->ec = new EC('secp256k1');
    }

    /**
     * Sign a transaction's `raw_data_hex` with the hot-wallet private
     * key. Returns the 130-char hex signature ready to drop into the
     * broadcast envelope's `signature: [<this>]` slot.
     *
     * @param  string  $rawDataHex  hex from /wallet/createtransaction
     * @param  string  $privateKeyHex  64-char hex (no `0x` prefix)
     */
    public function sign(string $rawDataHex, string $privateKeyHex): string
    {
        if (! ctype_xdigit($rawDataHex) || strlen($rawDataHex) % 2 !== 0) {
            throw new InvalidArgumentException('rawDataHex must be even-length hex');
        }
        if (! ctype_xdigit($privateKeyHex) || strlen($privateKeyHex) !== 64) {
            throw new InvalidArgumentException('privateKeyHex must be exactly 64 hex chars');
        }

        // Tron txid = sha256(raw_data); the broadcast endpoint expects
        // a signature over THIS digest. Using sha256 (FIPS 180) — NOT
        // keccak256 — because raw_data is already canonicalised by
        // the node before it returns raw_data_hex. Mismatching the
        // hash function silently produces "INVALID_SIGNATURE" replies.
        $msgHash = hash('sha256', hex2bin($rawDataHex));

        $key = $this->ec->keyFromPrivate($privateKeyHex, 'hex');
        // 'canonical' => true forces low-s form (BIP62-style). Tron
        // and every other secp256k1 chain reject high-s as a
        // malleability defence. simplito defaults to canonical=true
        // already; keeping the option explicit so a future default
        // flip doesn't silently break us.
        $signature = $key->sign($msgHash, ['canonical' => true]);

        // r and s come back as bn-php BigNumber instances. toString(16)
        // strips leading zeros — pad to 32 bytes (64 hex chars) so
        // the on-wire signature is always exactly 65 bytes. A
        // truncated r or s is a silent broadcast rejection.
        $r = str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT);
        $v = sprintf('%02x', $signature->recoveryParam ?? 0);

        return $r.$s.$v;
    }
}
