SatPeek — campaign not approved.

We couldn't approve "{{ $ad->title }}".

Your full {{ number_format($ad->total_cost_sat) }} sat budget has been refunded
to your SatPeek balance. You can edit the campaign and re-submit any time.

Reason: {{ $ad->rejection_reason ?: 'Operator review.' }}

© {{ date('Y') }} SatPeek
