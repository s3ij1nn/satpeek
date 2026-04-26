@php $w = $withdrawal; @endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Withdrawal not processed</title></head>
<body style="margin:0;padding:0;background:#07090f;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#07090f"><tr><td align="center" style="padding:48px 16px;">
    <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" bgcolor="#0c1018" style="max-width:560px;background:#0c1018;border:1px solid #1d2634;border-radius:16px;overflow:hidden;">
        <tr><td style="padding:40px 36px 8px;">
            <p style="margin:0 0 6px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.18em;color:#71717a;text-transform:uppercase;">/ withdrawal · refunded</p>
            <h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:32px;line-height:1.05;font-weight:400;color:#f4f6f9;letter-spacing:-0.02em;">
                We <span style="color:#fb7185;font-style:italic;">couldn't process</span> this payout.
            </h1>
            <p style="margin:0;font-family:-apple-system,sans-serif;font-size:15px;line-height:1.65;color:#aab4c2;">
                The funds — <strong style="color:#f4f6f9;">{{ number_format($w->amount_sat) }} sat</strong> — have been returned to your SatPeek balance. You can submit a new withdrawal at any time.
            </p>
        </td></tr>
        <tr><td style="padding:8px 36px 36px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#11161f" style="background:#11161f;border:1px solid #1d2634;border-radius:10px;">
                <tr><td style="padding:18px 20px;">
                    <p style="margin:0 0 8px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.12em;color:#71717a;text-transform:uppercase;">Reason</p>
                    <p style="margin:0;font-family:-apple-system,sans-serif;font-size:14px;color:#aab4c2;line-height:1.55;">
                        {{ $w->failure_reason ?: 'Operator review.' }}
                    </p>
                </td></tr>
            </table>
        </td></tr>
    </table>
    <p style="margin:18px 0 0;font-family:ui-monospace,monospace;font-size:10px;color:#4a5260;letter-spacing:.1em;">© {{ date('Y') }} SatPeek</p>
</td></tr></table>
</body></html>
