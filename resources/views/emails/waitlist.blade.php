<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>You're on the SatPeek waitlist</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td, a { font-family: Arial, sans-serif !important; }
    </style>
    <![endif]-->
</head>
<body style="margin:0; padding:0; background:#07090f; -webkit-font-smoothing: antialiased;">
    <!-- Hidden preheader (preview text in inbox) -->
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#07090f;">
        Your spot is reserved · {{ number_format($minWithdrawSat) }} sat min payout · {{ $referralCommissionPct }}% lifetime referral cut · activation link arrives at launch.
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#07090f" style="background:#07090f;">
        <tr>
            <td align="center" style="padding: 48px 16px;">

                {{-- Brand mark --}}
                <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;">
                    <tr>
                        <td align="center" style="padding-bottom: 28px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td valign="middle" style="padding-right: 10px;">
                                        <div style="width:24px; height:24px; border-radius:50%; background:#f59e0b; box-shadow: 0 0 18px rgba(245,158,11,0.45); line-height:24px;">&nbsp;</div>
                                    </td>
                                    <td valign="middle" style="font-family: Georgia, 'Iowan Old Style', 'Apple Garamond', serif; font-style: italic; font-size:22px; color:#f4f6f9; letter-spacing:-0.01em;">
                                        SatPeek
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                {{-- Main card --}}
                <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" bgcolor="#0c1018"
                       style="max-width:560px; background:#0c1018; border:1px solid #1d2634; border-radius:16px; overflow:hidden;">

                    {{-- Hero --}}
                    <tr>
                        <td style="padding: 40px 36px 16px;">
                            <p style="margin:0 0 6px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:11px; letter-spacing:0.18em; color:#71717a; text-transform:uppercase;">
                                / waitlist · confirmed
                            </p>
                            <h1 style="margin:0 0 14px; font-family: Georgia, 'Iowan Old Style', 'Apple Garamond', serif; font-size:38px; line-height:1.05; font-weight:400; color:#f4f6f9; letter-spacing:-0.02em;">
                                You're on <span style="color:#fcd34d; font-style:italic;">the list</span>.
                            </h1>
                            <p style="margin:0; font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; font-size:15px; line-height:1.65; color:#aab4c2;">
                                Thanks for reserving your spot on SatPeek — the bot-resistant paid-to-click platform that pays out in satoshi via FaucetPay.
                                We'll email <strong style="color:#f4f6f9;">{{ $email }}</strong> the moment activation opens.
                            </p>
                        </td>
                    </tr>

                    {{-- Reservation details --}}
                    <tr>
                        <td style="padding: 16px 36px 8px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                                   bgcolor="#11161f"
                                   style="background:#11161f; border:1px solid #1d2634; border-radius:10px;">
                                <tr>
                                    <td style="padding: 18px 20px;">
                                        <p style="margin:0 0 12px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:10px; letter-spacing:0.16em; color:#71717a; text-transform:uppercase;">
                                            Your reservation
                                        </p>
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; font-size:14px; color:#aab4c2;">
                                            <tr>
                                                <td width="120" style="padding:6px 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:11px; color:#71717a; text-transform:uppercase; letter-spacing:0.12em;">Email</td>
                                                <td style="padding:6px 0; color:#f4f6f9;"><strong style="font-weight:500;">{{ $email }}</strong></td>
                                            </tr>
                                            @if ($faucetpayEmail)
                                            <tr>
                                                <td style="padding:6px 0; border-top:1px solid #1d2634; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:11px; color:#71717a; text-transform:uppercase; letter-spacing:0.12em;">FaucetPay</td>
                                                <td style="padding:6px 0; border-top:1px solid #1d2634; color:#f4f6f9;">{{ $faucetpayEmail }}</td>
                                            </tr>
                                            @endif
                                            @if ($referralCode)
                                            <tr>
                                                <td style="padding:6px 0; border-top:1px solid #1d2634; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:11px; color:#71717a; text-transform:uppercase; letter-spacing:0.12em;">Referral</td>
                                                <td style="padding:6px 0; border-top:1px solid #1d2634;"><span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color:#fcd34d;">{{ $referralCode }}</span></td>
                                            </tr>
                                            @endif
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Numbers strip — what you get on launch --}}
                    <tr>
                        <td style="padding: 16px 36px 8px;">
                            <p style="margin:0 0 10px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:10px; letter-spacing:0.16em; color:#71717a; text-transform:uppercase;">
                                What you'll get on launch day
                            </p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td width="50%" valign="top" style="padding-right:6px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#11161f"
                                               style="background:#11161f; border:1px solid #1d2634; border-radius:10px;">
                                            <tr>
                                                <td style="padding: 16px 18px;">
                                                    <div style="font-family: Georgia, serif; font-size:30px; line-height:1; color:#fcd34d; letter-spacing:-0.01em;">
                                                        {{ number_format($minWithdrawSat) }}<span style="font-family: ui-monospace, monospace; font-size:11px; color:#71717a; margin-left:4px;">sat</span>
                                                    </div>
                                                    <div style="margin-top:8px; font-family: ui-monospace, monospace; font-size:10px; letter-spacing:0.12em; color:#71717a; text-transform:uppercase;">
                                                        Min withdrawal
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td width="50%" valign="top" style="padding-left:6px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#11161f"
                                               style="background:#11161f; border:1px solid #1d2634; border-radius:10px;">
                                            <tr>
                                                <td style="padding: 16px 18px;">
                                                    <div style="font-family: Georgia, serif; font-size:30px; line-height:1; color:#fcd34d; letter-spacing:-0.01em;">
                                                        {{ $referralCommissionPct }}<span style="font-family: ui-monospace, monospace; font-size:11px; color:#71717a; margin-left:4px;">%</span>
                                                    </div>
                                                    <div style="margin-top:8px; font-family: ui-monospace, monospace; font-size:10px; letter-spacing:0.12em; color:#71717a; text-transform:uppercase;">
                                                        Lifetime referral cut
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- What makes SatPeek different (3-bullet pitch) --}}
                    <tr>
                        <td style="padding: 24px 36px 8px;">
                            <p style="margin:0 0 14px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:10px; letter-spacing:0.16em; color:#71717a; text-transform:uppercase;">
                                Why your sats add up faster here
                            </p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; font-size:14px; color:#aab4c2; line-height:1.55;">
                                <tr>
                                    <td width="22" valign="top" style="padding:6px 0; color:#fcd34d; font-weight:600;">→</td>
                                    <td style="padding:6px 0;">A <strong style="color:#f4f6f9; font-weight:500;">trajectory captcha</strong> 2captcha cannot relay. Cleaner inventory means more sats per click.</td>
                                </tr>
                                <tr>
                                    <td width="22" valign="top" style="padding:6px 0; color:#fcd34d; font-weight:600;">→</td>
                                    <td style="padding:6px 0;">Withdraw to <strong style="color:#f4f6f9; font-weight:500;">FaucetPay</strong> in BTC, DOGE, LTC, ETH, USDT, or TRX.</td>
                                </tr>
                                <tr>
                                    <td width="22" valign="top" style="padding:6px 0; color:#fcd34d; font-weight:600;">→</td>
                                    <td style="padding:6px 0;">No faucet, no fake balances — payouts run from a queue with auto-processing.</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- CTA --}}
                    <tr>
                        <td align="center" style="padding: 28px 36px 36px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td bgcolor="#f59e0b" style="background:#f59e0b; border-radius:10px;">
                                        <a href="{{ url('/') }}" target="_blank"
                                           style="display:inline-block; padding:13px 26px; font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; font-size:14px; font-weight:600; color:#1a0e00; text-decoration:none; border-radius:10px;">
                                            Visit SatPeek &nbsp;→
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:14px 0 0; font-family: ui-monospace, monospace; font-size:11px; color:#4a5260;">
                                We'll send the activation link to <strong style="color:#71717a; font-weight:500;">{{ $email }}</strong> once doors open.
                            </p>
                        </td>
                    </tr>
                </table>

                {{-- Footer --}}
                <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;">
                    <tr>
                        <td align="center" style="padding: 24px 16px 0; font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; font-size:12px; line-height:1.6; color:#4a5260;">
                            If you didn't request this, you can ignore the message — your address won't be activated unless you respond to the launch email.
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 12px 16px 0; font-family: ui-monospace, monospace; font-size:10px; color:#4a5260; letter-spacing:0.1em;">
                            © {{ date('Y') }} SatPeek &nbsp;·&nbsp; bot-resistant PTC
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
