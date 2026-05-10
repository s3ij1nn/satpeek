SatPeek — hot wallet low balance

One or more hot-wallet monitors flipped to `down`. Pending withdrawals against
these currencies will start failing once the queue catches up. Top up the wallet
or pause the affected payout route.

@foreach ($downRows as $row)
{{ str_pad((string) $row['code'], 12) }} {{ $row['status'] }}@if (($row['status'] ?? '') !== 'unavailable') · avail {{ $row['available'] ?? '?' }} · req {{ $row['required'] ?? '?' }} · gap {{ $row['gap'] ?? '?' }}@endif

@endforeach

Open the dashboard: {{ url('/admin') }}

© {{ date('Y') }} SatPeek
