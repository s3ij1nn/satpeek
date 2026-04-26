@php $a = $ad; @endphp
<!doctype html>
<html><head><meta charset="utf-8"><title>Campaign approved</title></head>
<body style="margin:0;padding:0;background:#07090f;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#07090f"><tr><td align="center" style="padding:48px 16px;">
    <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" bgcolor="#0c1018" style="max-width:560px;background:#0c1018;border:1px solid #1d2634;border-radius:16px;overflow:hidden;">
        <tr><td style="padding:40px 36px 8px;">
            <p style="margin:0 0 6px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.18em;color:#71717a;text-transform:uppercase;">/ campaign · approved</p>
            <h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:30px;line-height:1.05;font-weight:400;color:#f4f6f9;letter-spacing:-0.02em;">
                "{{ $a->title }}" is <span style="color:#34d399;font-style:italic;">live</span>.
            </h1>
            <p style="margin:0;font-family:-apple-system,sans-serif;font-size:15px;line-height:1.65;color:#aab4c2;">
                Approved and serving impressions to SatPeek users now. {{ number_format($a->views_remaining) }} views remaining out of {{ number_format($a->total_views_purchased) }}.
            </p>
        </td></tr>
        <tr><td align="center" style="padding:24px 36px 36px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>
                <td bgcolor="#f59e0b" style="background:#f59e0b;border-radius:10px;">
                    <a href="{{ url('/advertise/'.$a->id) }}" target="_blank" style="display:inline-block;padding:13px 26px;font-family:-apple-system,sans-serif;font-size:14px;font-weight:600;color:#1a0e00;text-decoration:none;border-radius:10px;">Track performance &nbsp;→</a>
                </td>
            </tr></table>
        </td></tr>
    </table>
    <p style="margin:18px 0 0;font-family:ui-monospace,monospace;font-size:10px;color:#4a5260;letter-spacing:.1em;">© {{ date('Y') }} SatPeek</p>
</td></tr></table>
</body></html>
