SatPeek — campaign {{ $ad->status === 'approved' ? 'live' : 'in review' }}.

Title:        "{{ $ad->title }}"
Reward/view:  {{ number_format($ad->reward_sat) }} sat
Cost/view:    {{ number_format($ad->cost_per_view_sat) }} sat (incl. fee)
Views:        {{ number_format($ad->total_views_purchased) }}
Total spent:  {{ number_format($ad->total_cost_sat) }} sat
Status:       {{ $ad->status }}

@if ($ad->status === 'approved')
The ad is being shown to other SatPeek users right now. We'll email
when the budget is fully spent.
@else
A team member will review the URL — typically within 24 hours.
@endif

Manage your campaign: {{ url('/advertise/'.$ad->id) }}

© {{ date('Y') }} SatPeek
