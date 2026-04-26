SatPeek — withdrawal not processed.

We couldn't process the {{ number_format($withdrawal->amount_sat) }} sat payout request.
The funds have been returned to your SatPeek balance — you can submit a new
withdrawal at any time.

Reason: {{ $withdrawal->failure_reason ?: 'Operator review.' }}

© {{ date('Y') }} SatPeek
