@php
    $w = $withdrawal;
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Withdrawal received</title></head>
<body style="margin:0;padding:0;background:#07090f;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#07090f"><tr><td align="center" style="padding:48px 16px;">
    <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" bgcolor="#0c1018" style="max-width:560px;background:#0c1018;border:1px solid #1d2634;border-radius:16px;overflow:hidden;">
        <tr><td style="padding:40px 36px 8px;">
            <p style="margin:0 0 6px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.18em;color:#71717a;text-transform:uppercase;">/ withdrawal · {{ $requiresReview ? 'awaiting review' : 'queued' }}</p>
            <h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:32px;line-height:1.05;font-weight:400;color:#f4f6f9;letter-spacing:-0.02em;">
                Your <span style="color:#fcd34d;font-style:italic;">payout request</span> is in.
            </h1>
            <p style="margin:0;font-family:-apple-system,sans-serif;font-size:15px;line-height:1.65;color:#aab4c2;">
                @if ($requiresReview)
                    A team member will review the request shortly. Once approved, the payout enters the FaucetPay queue and you'll receive a "sent" confirmation.
                @else
                    The request is queued for FaucetPay processing. We'll email you the moment it's sent.
                @endif
            </p>
        </td></tr>
        <tr><td style="padding:8px 36px 36px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#11161f" style="background:#11161f;border:1px solid #1d2634;border-radius:10px;">
                <tr><td style="padding:18px 20px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="font-family:-apple-system,sans-serif;font-size:14px;color:#aab4c2;">
                        <tr><td width="120" style="padding:6px 0;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.12em;">Amount</td>
                            <td style="padding:6px 0;color:#f4f6f9;"><strong style="font-weight:500;">{{ number_format($w->amount_sat) }} sat</strong></td></tr>
                        <tr><td style="padding:6px 0;border-top:1px solid #1d2634;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.12em;">Currency</td>
                            <td style="padding:6px 0;border-top:1px solid #1d2634;color:#f4f6f9;">{{ $w->currency }}</td></tr>
                        <tr><td style="padding:6px 0;border-top:1px solid #1d2634;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.12em;">FaucetPay</td>
                            <td style="padding:6px 0;border-top:1px solid #1d2634;color:#f4f6f9;">{{ $w->faucetpay_email }}</td></tr>
                        <tr><td style="padding:6px 0;border-top:1px solid #1d2634;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.12em;">Reference</td>
                            <td style="padding:6px 0;border-top:1px solid #1d2634;font-family:ui-monospace,monospace;color:#fcd34d;">satpeek-withdraw-{{ $w->id }}</td></tr>
                    </table>
                </td></tr>
            </table>
        </td></tr>
    </table>
    <p style="margin:18px 0 0;font-family:ui-monospace,monospace;font-size:10px;color:#4a5260;letter-spacing:.1em;">© {{ date('Y') }} SatPeek</p>
</td></tr></table>
</body></html>
