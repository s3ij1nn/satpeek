SatPeek — your withdrawal request is in.

Amount:    {{ number_format($withdrawal->amount_sat) }} sat
Currency:  {{ $withdrawal->currency }}
FaucetPay: {{ $withdrawal->faucetpay_email }}
Reference: satpeek-withdraw-{{ $withdrawal->id }}

@if ($requiresReview)
A team member will review the request shortly. Once approved the payout
enters the FaucetPay queue and you'll receive a "sent" confirmation.
@else
The request is queued for FaucetPay processing. We'll email you the
moment it's sent.
@endif

© {{ date('Y') }} SatPeek
