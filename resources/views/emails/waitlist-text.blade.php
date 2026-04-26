SatPeek — your waitlist spot is confirmed.

Thanks for reserving your spot on SatPeek, the bot-resistant paid-to-click
platform that pays out in satoshi via FaucetPay.

We'll email {{ $email }} the moment activation opens.

— Your reservation —
Email:    {{ $email }}
@if ($faucetpayEmail)FaucetPay: {{ $faucetpayEmail }}
@endif
@if ($referralCode)Referral: {{ $referralCode }}
@endif
— What you'll get on launch day —
• Min withdrawal:  {{ number_format($minWithdrawSat) }} sat
• Referral cut:    {{ $referralCommissionPct }}% lifetime

— Why your sats add up faster here —
• A trajectory captcha 2captcha cannot relay — cleaner inventory
  means more sats per click.
• Withdraw to FaucetPay in BTC, DOGE, LTC, ETH, USDT, or TRX.
• No faucet, no fake balances — payouts run from a queue.

Visit SatPeek: {{ url('/') }}

If you didn't request this you can ignore the message — your address
won't be activated unless you respond to the launch email.

© {{ date('Y') }} SatPeek
