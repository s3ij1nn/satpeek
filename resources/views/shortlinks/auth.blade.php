@extends('layouts.app')

@push('head')
<style>
    .sl-auth { max-width: 38rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 1.5rem; }
    .sl-auth__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: .5rem 0 .25rem; }
    .sl-auth__head h1 em { color: var(--amber-soft); font-style: italic; }
    .sl-auth__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }

    .panel { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem; display: grid; gap: 1rem; }
    .panel h2 { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0; font-weight: 500; }
    .panel.locked { opacity: .55; pointer-events: none; }

    .countdown { display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: center; padding: 1rem 1.25rem; background: var(--bg-elev); border-radius: var(--radius-md); }
    .countdown__label { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }
    .countdown__num { font-family: var(--font-display); font-size: 2rem; line-height: 1; color: var(--amber-soft); }
    .countdown__num small { font-family: var(--font-mono); font-size: .45em; color: var(--text-tertiary); margin-left: .15rem; }
    .countdown__bar { width: 100%; height: 4px; background: var(--bg-elev-2); border-radius: 999px; overflow: hidden; margin-top: .5rem; }
    .countdown__bar > div { height: 100%; background: var(--amber-soft); transition: width 1s linear; }

    .reward-pill { display: inline-flex; align-items: baseline; gap: .25rem; padding: .35rem .75rem; background: var(--bg-elev); border: 1px solid var(--border-subtle); border-radius: 999px; font-family: var(--font-mono); font-size: var(--text-sm); color: var(--amber-soft); }

    .alert--ok { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(52,211,153,.08); border: 1px solid rgba(52,211,153,.3); color: var(--mint); font-size: var(--text-sm); }
    .alert--err { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.08); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-size: var(--text-sm); }
</style>
@endpush

@section('content')
<section class="sl-auth">
    <header class="sl-auth__head">
        <span class="meta">/ shortlinks · claim</span>
        <h1>Shortlink earn</h1>
        <p style="color: var(--text-secondary); margin: .25rem 0 .75rem;">When the timer hits zero, solve the captcha and claim.</p>
        <span class="reward-pill">+{{ number_format($reward_sat) }}<small style="color: var(--text-tertiary);">sat</small></span>
    </header>

    <div id="slMsg" style="display:none;"></div>

    <div class="panel">
        <h2>Hold</h2>
        <div class="countdown">
            <div>
                <div class="countdown__label">Time remaining</div>
                <div class="countdown__bar"><div id="slBar" style="width: 0%;"></div></div>
            </div>
            <div class="countdown__num"><span id="slLeft">{{ $remaining_sec }}</span><small>sec</small></div>
        </div>
    </div>

    <div class="panel locked" id="slClaimPanel">
        <h2>Step 2 — Solve captcha to claim</h2>
        <x-trajectory-captcha name="sl-auth" />
        <button type="button" class="cta cta--primary" id="slClaim" disabled style="justify-content:center;">
            Claim {{ number_format($reward_sat) }} sat <span class="cta__arrow">→</span>
        </button>
    </div>

    <p style="font-size: var(--text-xs); color: var(--text-tertiary); text-align: center; margin: 0;">
        ← <a href="{{ route('shortlinks.index') }}" style="color: var(--text-secondary); text-decoration: underline;">Back to shortlinks</a>
    </p>
</section>
@endsection

@push('body')
<script>
(() => {
    // Token is part of the URL → no need to thread it through JSON. Same string
    // drives both the page identity and the AJAX completion call, so the
    // attacker would need to guess a 28-char random just to probe an existing
    // click — and even then it's user-scoped + single-use server-side.
    const token = @json($click->epoch_token);
    const holdTotal = {{ (int) $hold_seconds }};
    const fp = window.SPCaptcha?.fingerprint || '';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const leftEl = document.getElementById('slLeft');
    const barEl = document.getElementById('slBar');
    const panel = document.getElementById('slClaimPanel');
    const claim = document.getElementById('slClaim');
    const msgEl = document.getElementById('slMsg');

    let remaining = {{ (int) $remaining_sec }};

    function showMsg(state, text) {
        msgEl.style.display = 'block';
        msgEl.className = state === 'ok' ? 'alert--ok' : 'alert--err';
        msgEl.textContent = text;
    }

    function unlock() {
        panel.classList.remove('locked');
        claim.disabled = false;
        // Issue a fresh captcha challenge at the moment the user can engage,
        // not at page load — keeps the 60 s solve window aligned with intent.
        const cap = panel.querySelector('[data-trajectory-captcha]');
        if (cap?.spReset) cap.spReset();
    }

    function tickInit() {
        // Reflect the initial bar fill based on how much hold has already elapsed.
        const elapsed = Math.max(0, holdTotal - remaining);
        barEl.style.width = (elapsed / holdTotal * 100).toFixed(1) + '%';
        if (remaining === 0) { unlock(); return; }
        const id = setInterval(() => {
            remaining = Math.max(0, remaining - 1);
            leftEl.textContent = remaining;
            barEl.style.width = ((holdTotal - remaining) / holdTotal * 100).toFixed(1) + '%';
            if (remaining === 0) {
                clearInterval(id);
                unlock();
            }
        }, 1000);
    }
    tickInit();

    claim.addEventListener('click', async () => {
        const cap = panel.querySelector('[data-trajectory-captcha]');
        const state = cap?.spGetState ? cap.spGetState() : { challengeId: '', points: '', isReady: false };
        if (!state.isReady) { showMsg('err', 'Solve the captcha first.'); return; }

        try {
            const v = await fetch('/api/captcha/verify', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-SP-Fingerprint': fp },
                body: JSON.stringify({ challengeId: state.challengeId, points: JSON.parse(state.points) }),
                credentials: 'same-origin',
            });
            const vd = await v.json();
            if (!v.ok || !vd.passed) {
                showMsg('err', `Captcha rejected (${vd.reason || 'unknown'}).`);
                if (cap?.spReset) await cap.spReset();
                return;
            }

            const c = await fetch(`/api/shortlinks/auth/${encodeURIComponent(token)}/complete`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-SP-Fingerprint': fp },
                body: JSON.stringify({ epoch_token: token, captcha_challenge_id: state.challengeId }),
                credentials: 'same-origin',
            });
            const cd = await c.json();
            if (!c.ok) { showMsg('err', cd?.error || 'Could not claim.'); return; }
            showMsg('ok', `Credited ${cd.reward_sat} sat. Returning to shortlinks…`);
            setTimeout(() => location.href = '{{ route('shortlinks.index') }}', 1400);
        } catch (e) {
            showMsg('err', 'Network error during claim.');
        }
    });
})();
</script>
@endpush
