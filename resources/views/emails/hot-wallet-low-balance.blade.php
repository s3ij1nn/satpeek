<!doctype html>
<html><head><meta charset="utf-8"><title>SatPeek hot wallet alert</title></head>
<body style="margin:0;padding:0;background:#07090f;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#07090f"><tr><td align="center" style="padding:48px 16px;">
    <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" bgcolor="#0c1018" style="max-width:600px;background:#0c1018;border:1px solid #4b1d20;border-radius:16px;overflow:hidden;">
        <tr><td style="padding:40px 36px 8px;">
            <p style="margin:0 0 6px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.18em;color:#dc2626;text-transform:uppercase;">/ alert · hot wallet</p>
            <h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:26px;line-height:1.1;font-weight:400;color:#f4f6f9;letter-spacing:-0.02em;">
                Hot wallet <span style="color:#dc2626;font-style:italic;">runway exhausted</span>
            </h1>
            <p style="margin:0 0 18px;font-family:ui-monospace,monospace;font-size:12px;color:#aab4c2;line-height:1.5;">
                One or more hot-wallet monitors flipped to <span style="color:#dc2626;">down</span>. Pending withdrawals against these currencies will start failing once the queue catches up. Top up the wallet or pause the affected payout route.
            </p>
        </td></tr>

        <tr><td style="padding:8px 36px 24px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
@foreach ($downRows as $row)
                <tr>
                    <td style="padding:8px 0;font-family:ui-monospace,monospace;font-size:13px;color:#aab4c2;">{{ $row['code'] }}</td>
                    <td align="right" style="padding:8px 0;font-family:ui-monospace,monospace;font-size:12px;color:#71717a;">
@if (($row['status'] ?? '') === 'unavailable')
                        rpc unavailable
@elseif (($row['status'] ?? '') === 'low_runway')
                        avail {{ $row['available'] ?? '?' }} · runway ~{{ $row['runway_days'] ?? '?' }} days
@else
                        avail {{ $row['available'] ?? '?' }} · req {{ $row['required'] ?? '?' }} · gap {{ $row['gap'] ?? '?' }}
@endif
                    </td>
                </tr>
@endforeach
            </table>
        </td></tr>

        <tr><td align="center" style="padding:8px 36px 36px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>
                <td bgcolor="#dc2626" style="background:#dc2626;border-radius:10px;">
                    <a href="{{ url('/admin') }}" target="_blank" style="display:inline-block;padding:13px 26px;font-family:-apple-system,sans-serif;font-size:14px;font-weight:600;color:#f4f6f9;text-decoration:none;border-radius:10px;">Open dashboard &nbsp;→</a>
                </td>
            </tr></table>
        </td></tr>
    </table>
    <p style="margin:18px 0 0;font-family:ui-monospace,monospace;font-size:10px;color:#4a5260;letter-spacing:.1em;">© {{ date('Y') }} SatPeek</p>
</td></tr></table>
</body></html>
