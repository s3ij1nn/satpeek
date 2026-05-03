@php
    $s = $summary;
    $row = function (string $label, array $bucket): string {
        $sign = $bucket['delta'] >= 0 ? '+' : '';
        $color = $bucket['delta'] > 0 ? '#34d399' : ($bucket['delta'] < 0 ? '#fb7185' : '#71717a');
        return sprintf(
            '<tr><td style="padding:8px 0;font-family:ui-monospace,monospace;font-size:13px;color:#aab4c2;">%s</td><td align="right" style="padding:8px 0;font-family:ui-monospace,monospace;font-size:13px;color:#f4f6f9;">%s</td><td align="right" style="padding:8px 0 8px 16px;font-family:ui-monospace,monospace;font-size:12px;color:%s;">%s%s</td></tr>',
            e($label),
            number_format((int) $bucket['this']),
            $color,
            e($sign),
            number_format((int) $bucket['delta']),
        );
    };
@endphp
<!doctype html>
<html><head><meta charset="utf-8"><title>SatPeek weekly summary</title></head>
<body style="margin:0;padding:0;background:#07090f;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#07090f"><tr><td align="center" style="padding:48px 16px;">
    <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" bgcolor="#0c1018" style="max-width:600px;background:#0c1018;border:1px solid #1d2634;border-radius:16px;overflow:hidden;">
        <tr><td style="padding:40px 36px 8px;">
            <p style="margin:0 0 6px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.18em;color:#71717a;text-transform:uppercase;">/ operator · weekly</p>
            <h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:28px;line-height:1.1;font-weight:400;color:#f4f6f9;letter-spacing:-0.02em;">
                Last <span style="color:#f59e0b;font-style:italic;">7 days</span>
            </h1>
            <p style="margin:0;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;">
                {{ \Illuminate\Support\Carbon::parse($s['window']['this_start'])->toDateString() }} → {{ \Illuminate\Support\Carbon::parse($s['window']['this_end'])->toDateString() }}
            </p>
        </td></tr>

        <tr><td style="padding:24px 36px 8px;">
            <p style="margin:0 0 8px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.14em;color:#71717a;text-transform:uppercase;">Earning activity (verified)</p>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                {!! $row('PTC views', $s['earning']['ptc_views']) !!}
                {!! $row('Shortlinks', $s['earning']['shortlink_clicks']) !!}
                {!! $row('Article reads', $s['earning']['article_reads']) !!}
            </table>
        </td></tr>

        <tr><td style="padding:24px 36px 8px;">
            <p style="margin:0 0 8px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.14em;color:#71717a;text-transform:uppercase;">Payouts</p>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr><td style="padding:8px 0;font-family:ui-monospace,monospace;font-size:13px;color:#aab4c2;">Sent</td><td align="right" style="padding:8px 0;font-family:ui-monospace,monospace;font-size:13px;color:#34d399;">{{ number_format($s['payouts']['sent_count']) }} ({{ number_format($s['payouts']['sent_total_sat']) }} sat)</td></tr>
                <tr><td style="padding:8px 0;font-family:ui-monospace,monospace;font-size:13px;color:#aab4c2;">Failed</td><td align="right" style="padding:8px 0;font-family:ui-monospace,monospace;font-size:13px;color:{{ $s['payouts']['failed_count'] > 0 ? '#fb7185' : '#f4f6f9' }};">{{ number_format($s['payouts']['failed_count']) }}</td></tr>
                <tr><td style="padding:8px 0;font-family:ui-monospace,monospace;font-size:13px;color:#aab4c2;">On hold (review)</td><td align="right" style="padding:8px 0;font-family:ui-monospace,monospace;font-size:13px;color:{{ $s['payouts']['hold_count'] > 0 ? '#fbbf24' : '#f4f6f9' }};">{{ number_format($s['payouts']['hold_count']) }}</td></tr>
            </table>
        </td></tr>

        <tr><td style="padding:24px 36px 8px;">
            <p style="margin:0 0 8px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.14em;color:#71717a;text-transform:uppercase;">Users</p>
            <p style="margin:0;font-family:ui-monospace,monospace;font-size:13px;color:#aab4c2;">
                <span style="color:#f4f6f9;">{{ number_format($s['users']['new_this_week']) }}</span> new this week
                <span style="color:#71717a;">(prev {{ number_format($s['users']['new_previous_week']) }})</span>
            </p>
        </td></tr>

        <tr><td style="padding:24px 36px 8px;">
            <p style="margin:0 0 8px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.14em;color:#71717a;text-transform:uppercase;">Bot tier evaluations (non-trust)</p>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr><td style="padding:6px 0;font-family:ui-monospace,monospace;font-size:13px;color:#fbbf24;">suspect</td><td align="right" style="padding:6px 0;font-family:ui-monospace,monospace;font-size:13px;color:#f4f6f9;">{{ number_format($s['tier_transitions']['suspect']) }}</td></tr>
                <tr><td style="padding:6px 0;font-family:ui-monospace,monospace;font-size:13px;color:#fb7185;">likely_bot</td><td align="right" style="padding:6px 0;font-family:ui-monospace,monospace;font-size:13px;color:#f4f6f9;">{{ number_format($s['tier_transitions']['likely_bot']) }}</td></tr>
                <tr><td style="padding:6px 0;font-family:ui-monospace,monospace;font-size:13px;color:#dc2626;">banned</td><td align="right" style="padding:6px 0;font-family:ui-monospace,monospace;font-size:13px;color:#f4f6f9;">{{ number_format($s['tier_transitions']['banned']) }}</td></tr>
            </table>
        </td></tr>

        <tr><td align="center" style="padding:24px 36px 36px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>
                <td bgcolor="#f59e0b" style="background:#f59e0b;border-radius:10px;">
                    <a href="{{ url('/admin') }}" target="_blank" style="display:inline-block;padding:13px 26px;font-family:-apple-system,sans-serif;font-size:14px;font-weight:600;color:#1a0e00;text-decoration:none;border-radius:10px;">Open dashboard &nbsp;→</a>
                </td>
            </tr></table>
        </td></tr>
    </table>
    <p style="margin:18px 0 0;font-family:ui-monospace,monospace;font-size:10px;color:#4a5260;letter-spacing:.1em;">© {{ date('Y') }} SatPeek</p>
</td></tr></table>
</body></html>
