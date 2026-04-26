@php $a = $ad; $live = $a->status === 'approved'; @endphp
<!doctype html>
<html><head><meta charset="utf-8"><title>Campaign {{ $live ? 'live' : 'in review' }}</title></head>
<body style="margin:0;padding:0;background:#07090f;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#07090f"><tr><td align="center" style="padding:48px 16px;">
    <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" bgcolor="#0c1018" style="max-width:560px;background:#0c1018;border:1px solid #1d2634;border-radius:16px;overflow:hidden;">
        <tr><td style="padding:40px 36px 8px;">
            <p style="margin:0 0 6px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.18em;color:#71717a;text-transform:uppercase;">/ campaign · {{ $live ? 'live' : 'in review' }}</p>
            <h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:30px;line-height:1.05;font-weight:400;color:#f4f6f9;letter-spacing:-0.02em;">
                "{{ $a->title }}" {!! $live ? 'is <span style="color:#34d399;font-style:italic;">live</span>.' : 'is <span style="color:#fcd34d;font-style:italic;">in review</span>.' !!}
            </h1>
            <p style="margin:0;font-family:-apple-system,sans-serif;font-size:15px;line-height:1.65;color:#aab4c2;">
                @if ($live)
                    Your ad is being shown to other SatPeek users right now. We'll email you when the budget is fully spent.
                @else
                    A team member will review the URL and approve typically within 24 hours. We'll email you the moment it's approved (or if we need changes).
                @endif
            </p>
        </td></tr>
        <tr><td style="padding:8px 36px 36px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#11161f" style="background:#11161f;border:1px solid #1d2634;border-radius:10px;">
                <tr><td style="padding:18px 20px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="font-family:-apple-system,sans-serif;font-size:14px;color:#aab4c2;">
                        <tr><td width="120" style="padding:6px 0;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.12em;">Reward / view</td>
                            <td style="padding:6px 0;color:#f4f6f9;"><strong>{{ number_format($a->reward_sat) }} sat</strong> to viewer</td></tr>
                        <tr><td style="padding:6px 0;border-top:1px solid #1d2634;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.12em;">Cost / view</td>
                            <td style="padding:6px 0;border-top:1px solid #1d2634;color:#f4f6f9;">{{ number_format($a->cost_per_view_sat) }} sat (incl. fee)</td></tr>
                        <tr><td style="padding:6px 0;border-top:1px solid #1d2634;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.12em;">Views</td>
                            <td style="padding:6px 0;border-top:1px solid #1d2634;color:#f4f6f9;">{{ number_format($a->total_views_purchased) }}</td></tr>
                        <tr><td style="padding:6px 0;border-top:1px solid #1d2634;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.12em;">Total spent</td>
                            <td style="padding:6px 0;border-top:1px solid #1d2634;color:#fcd34d;font-family:ui-monospace,monospace;">{{ number_format($a->total_cost_sat) }} sat</td></tr>
                    </table>
                </td></tr>
            </table>
        </td></tr>
        <tr><td align="center" style="padding:0 36px 36px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>
                <td bgcolor="#f59e0b" style="background:#f59e0b;border-radius:10px;">
                    <a href="{{ url('/advertise/'.$a->id) }}" target="_blank" style="display:inline-block;padding:13px 26px;font-family:-apple-system,sans-serif;font-size:14px;font-weight:600;color:#1a0e00;text-decoration:none;border-radius:10px;">View campaign &nbsp;→</a>
                </td>
            </tr></table>
        </td></tr>
    </table>
    <p style="margin:18px 0 0;font-family:ui-monospace,monospace;font-size:10px;color:#4a5260;letter-spacing:.1em;">© {{ date('Y') }} SatPeek</p>
</td></tr></table>
</body></html>
