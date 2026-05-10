<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $amount_sat
 * @property string $payout_method
 * @property string|null $payout_currency
 * @property int|null $payout_amount
 * @property string|null $payout_rate
 * @property string|null $destination
 * @property int $fee_sat
 * @property string|null $faucetpay_email legacy — pre-multi-currency rows
 * @property string|null $currency legacy — pre-multi-currency rows
 * @property WithdrawalStatus $status
 * @property string|null $faucetpay_payout_id
 * @property string|null $onchain_tx_hash
 * @property string|null $failure_reason
 * @property bool $requires_review
 * @property int|null $reviewed_by
 * @property Carbon|null $processed_at
 * @property Carbon|null $broadcast_at onchain: gateway accepted tx for relay; FaucetPay: same as processed_at
 * @property Carbon|null $confirmed_at onchain: reached finality threshold; FaucetPay: same as processed_at
 * @property int $confirmations_seen last observed confirmation count (0 for FaucetPay)
 * @property array<string, mixed>|null $meta
 * @property-read User|null $user
 * @property-read User|null $reviewer
 */
class Withdrawal extends Model
{
    public const METHOD_FAUCETPAY = 'faucetpay';

    /**
     * Legacy "any onchain" placeholder kept ONLY so pre-Phase-2b
     * historical rows + the prefix detector below stay coherent. New
     * code MUST use the per-chain `METHOD_ONCHAIN_*` constants below;
     * `WithdrawController` rejects bare `onchain` at validation time
     * because the gateway registry no longer routes it.
     */
    public const METHOD_ONCHAIN = 'onchain';

    public const METHOD_ONCHAIN_TRX = 'onchain_trx';

    public const METHOD_ONCHAIN_USDT_TRC20 = 'onchain_usdt_trc20';

    public const METHOD_ONCHAIN_ETH = 'onchain_eth';

    public const METHOD_ONCHAIN_BTC = 'onchain_btc';

    /**
     * Returns true when `payout_method` selects an onchain gateway —
     * either a per-chain method (`onchain_trx`, `onchain_btc`, etc) or
     * the legacy `onchain` placeholder. ProcessWithdrawalJob uses this
     * to pick `Broadcast` vs `Sent` on settle and to decide which
     * external_id column receives the gateway reference.
     */
    public static function isOnchainMethod(?string $method): bool
    {
        return $method !== null && str_starts_with($method, 'onchain');
    }

    protected $fillable = [
        'user_id',
        'amount_sat',
        'payout_method',
        'payout_currency',
        'payout_amount',
        'payout_rate',
        'destination',
        'fee_sat',
        'faucetpay_email',
        'currency',
        'status',
        'faucetpay_payout_id',
        'onchain_tx_hash',
        'failure_reason',
        'requires_review',
        'reviewed_by',
        'processed_at',
        'broadcast_at',
        'confirmed_at',
        'confirmations_seen',
        'meta',
    ];

    protected $casts = [
        'requires_review' => 'boolean',
        'processed_at' => 'datetime',
        'broadcast_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'status' => WithdrawalStatus::class,
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
