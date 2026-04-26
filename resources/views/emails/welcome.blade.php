<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Welcome to SatPeek</title>
</head>
<body style="margin:0;padding:0;background:#07090f;-webkit-font-smoothing:antialiased;">
    <div style="display:none;max-height:0;overflow:hidden;font-size:1px;line-height:1px;color:#07090f;">
        Verify your email and unlock withdrawals · {{ number_format($minWithdrawSat) }} sat min payout · {{ $referralCommissionPct }}% lifetime referral cut.
    </div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#07090f" style="background:#07090f;">
        <tr><td align="center" style="padding:48px 16px;">
            <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;">
                <tr><td align="center" style="padding-bottom:28px;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>
                        <td valign="middle" style="padding-right:10px;"><div style="width:24px;height:24px;border-radius:50%;background:#f59e0b;box-shadow:0 0 18px rgba(245,158,11,.45);">&nbsp;</div></td>
                        <td valign="middle" style="font-family:Georgia,'Iowan Old Style',serif;font-style:italic;font-size:22px;color:#f4f6f9;letter-spacing:-0.01em;">SatPeek</td>
                    </tr></table>
                </td></tr>
            </table>

            <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" bgcolor="#0c1018" style="max-width:560px;background:#0c1018;border:1px solid #1d2634;border-radius:16px;overflow:hidden;">
                <tr><td style="padding:40px 36px 16px;">
                    <p style="margin:0 0 6px;font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.18em;color:#71717a;text-transform:uppercase;">/ welcome aboard</p>
                    <h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:38px;line-height:1.05;font-weight:400;color:#f4f6f9;letter-spacing:-0.02em;">
                        Hi <span style="color:#fcd34d;font-style:italic;">{{ $username }}</span>.
                    </h1>
                    <p style="margin:0;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.65;color:#aab4c2;">
                        Your account is created. Click the button below to verify <strong style="color:#f4f6f9;">{{ $email }}</strong> — verification unlocks withdrawals to FaucetPay.
                    </p>
                </td></tr>

                <tr><td align="center" style="padding:28px 36px 32px;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>
                        <td bgcolor="#f59e0b" style="background:#f59e0b;border-radius:10px;">
                            <a href="{{ $verifyUrl }}" target="_blank" style="display:inline-block;padding:13px 26px;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:14px;font-weight:600;color:#1a0e00;text-decoration:none;border-radius:10px;">Verify my email &nbsp;→</a>
                        </td>
                    </tr></table>
                    <p style="margin:14px 0 0;font-family:ui-monospace,monospace;font-size:11px;color:#4a5260;">
                        Link expires in 60 minutes. <a href="{{ $verifyUrl }}" style="color:#71717a;">Or paste this URL.</a>
                    </p>
                </td></tr>

                <tr><td style="padding:0 36px 24px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#11161f" style="background:#11161f;border:1px solid #1d2634;border-radius:10px;">
                        <tr><td style="padding:18px 20px;">
                            <p style="margin:0 0 12px;font-family:ui-monospace,monospace;font-size:10px;letter-spacing:.16em;color:#71717a;text-transform:uppercase;">Your account</p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="font-family:-apple-system,sans-serif;font-size:14px;color:#aab4c2;">
                                <tr>
                                    <td width="120" style="padding:6px 0;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.12em;">Username</td>
                                    <td style="padding:6px 0;color:#f4f6f9;"><strong style="font-weight:500;">{{ $username }}</strong></td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;border-top:1px solid #1d2634;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.12em;">Email</td>
                                    <td style="padding:6px 0;border-top:1px solid #1d2634;color:#f4f6f9;">{{ $email }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;border-top:1px solid #1d2634;font-family:ui-monospace,monospace;font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.12em;">Referral</td>
                                    <td style="padding:6px 0;border-top:1px solid #1d2634;"><span style="font-family:ui-monospace,monospace;color:#fcd34d;">{{ $referralCode }}</span> <span style="color:#71717a;font-size:12px;">— share this code, earn {{ $referralCommissionPct }}% lifetime.</span></td>
                                </tr>
                            </table>
                        </td></tr>
                    </table>
                </td></tr>

                <tr><td style="padding:0 36px 36px;">
                    <p style="margin:0 0 8px;font-family:ui-monospace,monospace;font-size:10px;letter-spacing:.16em;color:#71717a;text-transform:uppercase;">Quick start</p>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="font-family:-apple-system,sans-serif;font-size:14px;color:#aab4c2;line-height:1.55;">
                        <tr><td width="22" valign="top" style="padding:5px 0;color:#fcd34d;font-weight:600;">→</td><td style="padding:5px 0;">Open <strong style="color:#f4f6f9;font-weight:500;">PTC</strong> to view ads in exchange for sats.</td></tr>
                        <tr><td width="22" valign="top" style="padding:5px 0;color:#fcd34d;font-weight:600;">→</td><td style="padding:5px 0;">Click <strong style="color:#f4f6f9;font-weight:500;">Shortlinks</strong> for one-step rewards.</td></tr>
                        <tr><td width="22" valign="top" style="padding:5px 0;color:#fcd34d;font-weight:600;">→</td><td style="padding:5px 0;">Withdraw to FaucetPay once you reach <strong style="color:#f4f6f9;font-weight:500;">{{ number_format($minWithdrawSat) }} sat</strong>.</td></tr>
                    </table>
                </td></tr>
            </table>

            <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;">
                <tr><td align="center" style="padding:24px 16px 0;font-family:-apple-system,sans-serif;font-size:12px;line-height:1.6;color:#4a5260;">
                    Didn't sign up? You can ignore this email — without verification the account stays inactive.
                </td></tr>
                <tr><td align="center" style="padding:12px 16px 0;font-family:ui-monospace,monospace;font-size:10px;color:#4a5260;letter-spacing:.1em;">
                    © {{ date('Y') }} SatPeek &nbsp;·&nbsp; bot-resistant PTC
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
