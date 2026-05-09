@extends('layouts.app')

@php
    use App\Models\Withdrawal;
    use App\Payout\PayoutCurrencyRegistry;
    $u = auth()->user();
    $verified = $u->hasVerifiedEmail();
    $recent = Withdrawal::where('user_id', $u->id)->orderByDesc('id')->limit(10)->get();
    // Multi-currency: pull the FaucetPay-supported set from the registry
    // so adding a new currency in config/satpeek.php surfaces here on the
    // next page render. Legacy `min_withdraw_sat` stays for the form's
    // initial-state fallback before JS swaps the per-currency floor in.
    $registry = app(PayoutCurrencyRegistry::class);
    $currencies = $registry->faucetpaySupported();
    // Inline JSON so the JS dropdown handler can read per-currency floors
    // without an extra round-trip. Limited to fields the client needs.
    $currencyMeta = collect($currencies)->mapWithKeys(fn ($c) => [
        $c->code => [
            'label' => $c->label,
            'min_sat' => $c->minWithdrawSat,
        ],
    ])->all();
    $defaultCurrency = $currencies[0]->code ?? 'BTC';
    $defaultMin = $currencies[0]->minWithdrawSat ?? 1000;
@endphp

@push('head')
<style>
    .wd { max-width: 56rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 2rem; grid-template-columns: 1fr 1fr; }
    @media (max-width: 720px) { .wd { grid-template-columns: 1fr; } }
    .wd__head { grid-column: 1 / -1; }
    .wd__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: .5rem 0 .25rem; }
    .wd__head h1 em { color: var(--amber-soft); font-style: italic; }
    .wd__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }

    .form-card { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem; display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
    .form-card > * { min-width: 0; }
    .field { display: grid; gap: .4rem; }
    .field label { font-family: var(--font-mono); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .14em; color: var(--text-tertiary); }
    .field input, .field select { background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: .75rem .875rem; color: var(--text-primary); font: inherit; }
    .field input:focus, .field select:focus { outline: 0; border-color: var(--amber); box-shadow: 0 0 0 3px var(--amber-glow); }
    .field__hint { font-size: var(--text-xs); color: var(--text-tertiary); }

    .balance-pill { display: inline-flex; align-items: baseline; gap: .35rem; padding: .35rem .75rem; background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: 999px; font-family: var(--font-mono); font-size: var(--text-sm); color: var(--text-primary); }

    .alert--ok { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(52,211,153,.08); border: 1px solid rgba(52,211,153,.3); color: var(--mint); font-size: var(--text-sm); }
    .alert--err { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.08); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-size: var(--text-sm); }
    .alert--warn { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(252,211,77,.08); border: 1px solid rgba(252,211,77,.3); color: var(--amber-soft); font-size: var(--text-sm); }

    .panel { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem; }
    .panel h2 { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0 0 1rem; font-weight: 500; }
    .ledger { list-style: none; padding: 0; margin: 0; display: grid; gap: .25rem; }
    .ledger li { display: grid; grid-template-columns: 1fr auto; gap: 1rem; padding: .5rem 0; border-top: 1px solid var(--border-faint); align-items: baseline; font-size: var(--text-sm); }
    .ledger li:first-child { border-top: 0; }
    .ledger .when { font-family: var(--font-mono); font-size: .65rem; color: var(--text-tertiary); }
    .ledger .reason { color: var(--text-secondary); }
    .ledger .delta { font-family: var(--font-mono); font-size: var(--text-sm); white-space: nowrap; }
    .tier-badge { display: inline-block; padding: .12rem .5rem; border-radius: 4px; font-family: var(--font-mono); font-size: .7em; text-transform: uppercase; }
    .tier-sent  { background: rgba(52,211,153,.12); color: var(--mint); }
    .tier-failed, .tier-rejected { background: rgba(251,113,133,.12); color: var(--rose); }
    .tier-hold  { background: rgba(252,211,77,.12); color: var(--amber-soft); }
    .tier-queued, .tier-processing { background: rgba(103,232,249,.12); color: var(--cyan); }
</style>
@endpush

@section('content')
<section class="wd">
    <header class="wd__head">
        <span class="meta">/ withdraw to faucetpay</span>
        <h1>Cash <em>out</em>.</h1>
        <p style="color: var(--text-secondary); margin: 0;">Pick a currency and a FaucetPay address — your sat balance converts to the chosen coin at submit-time using live exchange rates.</p>
    </header>

    <div>
        @unless ($verified)
            <div class="alert--warn" role="alert" style="margin-bottom: 1rem;">
                ⚠ Verify your email first — withdrawals are locked until then.
                <a href="{{ route('verification.notice') }}" style="color: var(--amber-soft); text-decoration: underline;">Resend link →</a>
            </div>
        @endunless

        @if (session('status')) <div class="alert--ok" role="status" style="margin-bottom: 1rem;">{{ session('status') }}</div> @endif
        <div id="wdMsg" role="alert" aria-live="assertive" style="display:none; margin-bottom: 1rem;"></div>

        <div style="margin-bottom: 1rem;">
            <span class="balance-pill">{{ number_format($u->balance_sat) }} <small style="color: var(--text-tertiary);">sat available</small></span>
        </div>

        <form class="form-card" method="POST" action="/api/withdraw" id="wdForm" novalidate
              data-currency-meta='@json($currencyMeta)'>
            @csrf

            <div class="field">
                <label for="payout_currency">Currency</label>
                <select id="payout_currency" name="payout_currency" {{ $verified ? '' : 'disabled' }}>
                    @foreach ($currencies as $c)
                        <option value="{{ $c->code }}" {{ $c->code === $defaultCurrency ? 'selected' : '' }}>
                            {{ $c->label }} ({{ $c->code }})
                        </option>
                    @endforeach
                </select>
                <span class="field__hint" id="currencyHint">FaucetPay-routed payout. Conversion uses live BTC→target rates.</span>
            </div>

            <div class="field">
                <label for="amount_sat">Amount (sat)</label>
                <input id="amount_sat" name="amount_sat" type="number" min="{{ $defaultMin }}" max="{{ (int) $u->balance_sat }}" step="1" required
                       placeholder="{{ $defaultMin }}" value="{{ old('amount_sat', $defaultMin) }}" {{ $verified ? '' : 'disabled' }}>
                <span class="field__hint" id="minHint">Minimum <span id="minDisplay">{{ number_format($defaultMin) }}</span> sat — Maximum {{ number_format($u->balance_sat) }} sat.</span>
            </div>

            <div class="field">
                <label for="destination">FaucetPay address (email)</label>
                <input id="destination" name="destination" type="email" required autocomplete="email"
                       placeholder="you@faucetpay.io"
                       value="{{ old('destination', $u->faucetpay_email) }}" {{ $verified ? '' : 'disabled' }}>
                <span class="field__hint">Onchain (direct-network) payouts arrive in a future release.</span>
            </div>

            <input type="hidden" name="payout_method" value="faucetpay">

            <x-trajectory-captcha name="wd" />

            <button type="submit" class="cta cta--primary" id="wdSubmit"
                    {{ $verified ? '' : 'disabled' }}
                    style="margin-top:.5rem; justify-content:center;">
                Request payout <span class="cta__arrow">→</span>
            </button>
        </form>
    </div>

    <div class="panel">
        <h2>Recent withdrawals</h2>
        @if ($recent->isEmpty())
            <p style="color: var(--text-tertiary); font-size: var(--text-sm);">No withdrawals yet.</p>
        @else
            <ul class="ledger">
                @foreach ($recent as $w)
                    @php
                        // payout_currency / payout_amount are populated for
                        // post-Phase-1 rows; legacy rows fall back to the
                        // BTC-sat amount + the legacy currency column.
                        $cur = $w->payout_currency ?? strtoupper((string) $w->currency);
                        $dest = $w->destination ?? $w->faucetpay_email;
                        $payoutDisplay = $w->payout_amount
                            ? rtrim(rtrim($w->payout_amount, '0'), '.').' '.$cur
                            : number_format($w->amount_sat).' '.$cur;
                    @endphp
                    <li>
                        <span>
                            <span class="reason">{{ $payoutDisplay }} → {{ $dest }}</span><br>
                            <span class="when">{{ $w->created_at->diffForHumans() }} · <span class="tier-badge tier-{{ $w->status }}">{{ $w->status }}</span></span>
                        </span>
                        <span class="delta">{{ $w->faucetpay_payout_id ? '#'.$w->faucetpay_payout_id : ($w->onchain_tx_hash ? substr($w->onchain_tx_hash, 0, 8).'…' : '—') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
@endsection

@push('body')
<script>
(() => {
    const form = document.getElementById('wdForm');
    const msgEl = document.getElementById('wdMsg');
    const submitBtn = document.getElementById('wdSubmit');
    const currencyEl = document.getElementById('payout_currency');
    const amountEl = document.getElementById('amount_sat');
    const minDisplay = document.getElementById('minDisplay');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const fp = window.SPCaptcha?.fingerprint || '';
    const meta = JSON.parse(form?.dataset?.currencyMeta || '{}');

    function showMsg(state, text) {
        msgEl.style.display = 'block';
        msgEl.className = state === 'ok' ? 'alert--ok' : 'alert--err';
        msgEl.textContent = text;
        msgEl.scrollIntoView({behavior:'smooth', block:'nearest'});
    }

    // Per-currency min: swap the input's `min` + the displayed floor when
    // the user switches currency. The server-side validator is the source
    // of truth; this is just live UX so users don't waste a submit on a
    // sub-minimum amount.
    function applyCurrencyMin() {
        const code = currencyEl?.value || '';
        const m = meta[code];
        if (!m || !amountEl || !minDisplay) return;
        amountEl.min = m.min_sat;
        amountEl.placeholder = m.min_sat;
        minDisplay.textContent = new Intl.NumberFormat().format(m.min_sat);
    }
    if (currencyEl) {
        currencyEl.addEventListener('change', applyCurrencyMin);
        applyCurrencyMin();
    }

    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        msgEl.style.display = 'none';

        const cap = form.querySelector('[data-trajectory-captcha]');
        const state = cap?.spGetState ? cap.spGetState() : { challengeId: '', points: '', isReady: false };
        if (!state.isReady) { showMsg('err', 'Solve the captcha first.'); return; }
        const challengeId = state.challengeId;
        const points = state.points;

        // Captcha verify so the server stamps + binds fingerprint before
        // /api/withdraw runs.
        try {
            const v = await fetch('/api/captcha/verify', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-SP-Fingerprint': fp },
                body: JSON.stringify({ challengeId, points: JSON.parse(points) }),
                credentials: 'same-origin',
            });
            const vd = await v.json();
            if (!v.ok || !vd.passed) { showMsg('err', `Captcha rejected (${vd.reason || 'unknown'}).`); return; }

            const original = submitBtn.innerHTML;
            submitBtn.disabled = true; submitBtn.innerHTML = 'Submitting…';

            const w = await fetch('/api/withdraw', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-SP-Fingerprint': fp },
                body: new FormData(form),
                credentials: 'same-origin',
            });
            const wd = await w.json();
            submitBtn.disabled = false; submitBtn.innerHTML = original;

            if (!w.ok) {
                // Surface the most actionable error variant. The server
                // returns shape { error, reason?, message?, min_sat?, currency? }.
                let msg = wd?.message || wd?.error || 'Could not submit withdrawal.';
                if (wd?.error === 'below_minimum' && wd?.min_sat && wd?.currency) {
                    msg = `Below minimum for ${wd.currency} (${new Intl.NumberFormat().format(wd.min_sat)} sat).`;
                }
                if (wd?.error === 'price_oracle_unavailable') {
                    msg = 'Live exchange rates are temporarily unavailable. Please retry in a moment.';
                }
                showMsg('err', msg);
                return;
            }
            const verb = wd.requires_review ? 'queued for review' : 'queued';
            const amountTxt = wd.payout_amount && wd.payout_currency
                ? `${wd.payout_amount} ${wd.payout_currency}`
                : 'payout';
            showMsg('ok', `${amountTxt} ${verb} (#${wd.id}). Confirmation email is on its way.`);
            setTimeout(() => location.reload(), 1800);
        } catch (e) {
            showMsg('err', 'Network error.');
        }
    });
})();
</script>
@endpush
