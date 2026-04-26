@extends('layouts.app')

@php
    use App\Models\Withdrawal;
    $u = auth()->user();
    $min = (int) config('satpeek.faucetpay.min_withdraw_sat', 1000);
    $verified = $u->hasVerifiedEmail();
    $recent = Withdrawal::where('user_id', $u->id)->orderByDesc('id')->limit(10)->get();
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
        <p style="color: var(--text-secondary); margin: 0;">Balance moves to FaucetPay via <code style="font-family: var(--font-mono); font-size: .9em; color: var(--cyan-soft);">/api/v1/send</code>. Min withdrawal {{ number_format($min) }} sat.</p>
    </header>

    <div>
        @unless ($verified)
            <div class="alert--warn" style="margin-bottom: 1rem;">
                ⚠ Verify your email first — withdrawals are locked until then.
                <a href="{{ route('verification.notice') }}" style="color: var(--amber-soft); text-decoration: underline;">Resend link →</a>
            </div>
        @endunless

        @if (session('status')) <div class="alert--ok" style="margin-bottom: 1rem;">{{ session('status') }}</div> @endif
        <div id="wdMsg" style="display:none; margin-bottom: 1rem;"></div>

        <div style="margin-bottom: 1rem;">
            <span class="balance-pill">{{ number_format($u->balance_sat) }} <small style="color: var(--text-tertiary);">sat available</small></span>
        </div>

        <form class="form-card" method="POST" action="/api/withdraw" id="wdForm" novalidate>
            @csrf
            <div class="field">
                <label for="amount_sat">Amount (sat)</label>
                <input id="amount_sat" name="amount_sat" type="number" min="{{ $min }}" max="{{ (int) $u->balance_sat }}" step="1" required
                       placeholder="{{ $min }}" value="{{ old('amount_sat', $min) }}" {{ $verified ? '' : 'disabled' }}>
                <span class="field__hint">Minimum {{ number_format($min) }} sat — Maximum {{ number_format($u->balance_sat) }} sat.</span>
            </div>
            <div class="field">
                <label for="faucetpay_email">FaucetPay address</label>
                <input id="faucetpay_email" name="faucetpay_email" type="email" required autocomplete="email"
                       value="{{ old('faucetpay_email', $u->faucetpay_email) }}" {{ $verified ? '' : 'disabled' }}>
            </div>
            <div class="field">
                <label for="currency">Currency</label>
                <select id="currency" name="currency" {{ $verified ? '' : 'disabled' }}>
                    @foreach (['BTC', 'DOGE', 'LTC', 'ETH', 'USDT', 'TRX'] as $c)
                        <option value="{{ $c }}">{{ $c }}</option>
                    @endforeach
                </select>
            </div>

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
                    <li>
                        <span>
                            <span class="reason">{{ number_format($w->amount_sat) }} {{ $w->currency }} → {{ $w->faucetpay_email }}</span><br>
                            <span class="when">{{ $w->created_at->diffForHumans() }} · <span class="tier-badge tier-{{ $w->status }}">{{ $w->status }}</span></span>
                        </span>
                        <span class="delta">{{ $w->faucetpay_payout_id ? '#'.$w->faucetpay_payout_id : '—' }}</span>
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
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const fp = window.SPCaptcha?.fingerprint || '';

    function showMsg(state, text) {
        msgEl.style.display = 'block';
        msgEl.className = state === 'ok' ? 'alert--ok' : 'alert--err';
        msgEl.textContent = text;
        msgEl.scrollIntoView({behavior:'smooth', block:'nearest'});
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

        // Captcha verify (so server side stamps + binds fingerprint).
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

            if (!w.ok) { showMsg('err', wd?.error || 'Could not submit withdrawal.'); return; }
            const verb = wd.requires_review ? 'queued for review' : 'queued';
            showMsg('ok', `Withdrawal ${verb} (#${wd.id}). Confirmation email is on its way.`);
            setTimeout(() => location.reload(), 1800);
        } catch (e) {
            showMsg('err', 'Network error.');
        }
    });
})();
</script>
@endpush
