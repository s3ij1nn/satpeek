SatPeek — campaign approved.

"{{ $ad->title }}" is live.

Views remaining: {{ number_format($ad->views_remaining) }} / {{ number_format($ad->total_views_purchased) }}
Reward / view:   {{ number_format($ad->reward_sat) }} sat

Track performance: {{ url('/advertise/'.$ad->id) }}

© {{ date('Y') }} SatPeek
