SatPeek — hot wallet low balance

One or more hot-wallet monitors flipped to `down`. Pending withdrawals against
these currencies will start failing once the queue catches up. Top up the wallet
or pause the affected payout route.

@foreach ($downRows as $row)
@php
    $line = str_pad((string) $row['code'], 12) . ' ' . $row['status'];
    if (($row['status'] ?? '') === 'low_runway') {
        $line .= ' · avail ' . ($row['available'] ?? '?') . ' · runway ~' . ($row['runway_days'] ?? '?') . ' days';
    } elseif (($row['status'] ?? '') !== 'unavailable') {
        $line .= ' · avail ' . ($row['available'] ?? '?') . ' · req ' . ($row['required'] ?? '?') . ' · gap ' . ($row['gap'] ?? '?');
    }
@endphp
{{ $line }}
@endforeach

Open the dashboard: {{ url('/admin') }}

© {{ date('Y') }} SatPeek
