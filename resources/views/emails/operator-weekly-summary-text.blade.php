SatPeek — operator weekly summary

Window: {{ \Illuminate\Support\Carbon::parse($summary['window']['this_start'])->toDateString() }} → {{ \Illuminate\Support\Carbon::parse($summary['window']['this_end'])->toDateString() }}

== Earning activity (verified) ==
PTC views        {{ number_format($summary['earning']['ptc_views']['this']) }}  (prev {{ number_format($summary['earning']['ptc_views']['previous']) }} · Δ {{ $summary['earning']['ptc_views']['delta'] >= 0 ? '+' : '' }}{{ number_format($summary['earning']['ptc_views']['delta']) }})
Shortlinks       {{ number_format($summary['earning']['shortlink_clicks']['this']) }}  (prev {{ number_format($summary['earning']['shortlink_clicks']['previous']) }} · Δ {{ $summary['earning']['shortlink_clicks']['delta'] >= 0 ? '+' : '' }}{{ number_format($summary['earning']['shortlink_clicks']['delta']) }})
Article reads    {{ number_format($summary['earning']['article_reads']['this']) }}  (prev {{ number_format($summary['earning']['article_reads']['previous']) }} · Δ {{ $summary['earning']['article_reads']['delta'] >= 0 ? '+' : '' }}{{ number_format($summary['earning']['article_reads']['delta']) }})

== Payouts ==
Sent             {{ number_format($summary['payouts']['sent_count']) }} ({{ number_format($summary['payouts']['sent_total_sat']) }} sat)
Failed           {{ number_format($summary['payouts']['failed_count']) }}
On hold (review) {{ number_format($summary['payouts']['hold_count']) }}

== Users ==
New this week    {{ number_format($summary['users']['new_this_week']) }} (prev {{ number_format($summary['users']['new_previous_week']) }})

@if (! empty($summary['hot_wallet']))
== Hot-wallet runway ==
@foreach ($summary['hot_wallet'] as $row)
{{ str_pad($row['code'], 12) }} {{ $row['status'] }}@if ($row['status'] !== 'unavailable') · avail {{ $row['available'] }} · req {{ $row['required'] }} · gap {{ $row['gap'] }}@endif

@endforeach

@endif
== Bot tier evaluations (this week, non-trust) ==
suspect          {{ number_format($summary['tier_transitions']['suspect']) }}
likely_bot       {{ number_format($summary['tier_transitions']['likely_bot']) }}
banned           {{ number_format($summary['tier_transitions']['banned']) }}

Open the dashboard: {{ url('/admin') }}

© {{ date('Y') }} SatPeek
