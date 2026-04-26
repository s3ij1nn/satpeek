SatPeek — payout sent.

{{ number_format($withdrawal->amount_sat) }} {{ $withdrawal->currency }} delivered to {{ $withdrawal->faucetpay_email }} via FaucetPay.

@if ($withdrawal->faucetpay_payout_id)Payout id: {{ $withdrawal->faucetpay_payout_id }}
@endif
Sent at:   {{ optional($withdrawal->processed_at)->toDateTimeString() }} UTC

© {{ date('Y') }} SatPeek
