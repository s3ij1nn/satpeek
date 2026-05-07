@extends('layouts.app')

@push('head')
<style>
    .sl-auth { max-width: 38rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 1.5rem; }
    .sl-auth__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: .5rem 0 .25rem; }
    .sl-auth__head h1 em { color: var(--amber-soft); font-style: italic; }
    .sl-auth__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }

    .panel { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem; display: grid; gap: 1rem; }
    .panel h2 { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0; font-weight: 500; }

    .reward-pill { display: inline-flex; align-items: baseline; gap: .25rem; padding: .35rem .75rem; background: var(--bg-elev); border: 1px solid var(--border-subtle); border-radius: 999px; font-family: var(--font-mono); font-size: var(--text-sm); color: var(--amber-soft); }

    .alert--ok { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(52,211,153,.08); border: 1px solid rgba(52,211,153,.3); color: var(--mint); font-size: var(--text-sm); }
    .alert--err { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.08); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-size: var(--text-sm); }
</style>
@endpush

@section('content')
<section class="sl-auth">
    <header class="sl-auth__head">
        <span class="meta">/ shortlinks · claim</span>
        <h1>Welcome <em>back</em>.</h1>
        <p style="color: var(--text-secondary); margin: .25rem 0 .75rem;">You've completed the shortener visit. Solve the captcha to claim your reward.</p>
        <span class="reward-pill">+{{ number_format($reward_sat) }}<small style="color: var(--text-tertiary);">sat</small></span>
    </header>

    {{-- Status / error sink. role=status + aria-live so SR users hear
         "captcha required" / "claimed +X sat" without a manual reload. --}}
    <div id="slMsg" role="status" aria-live="polite" style="display:none;"></div>

    <div class="panel" id="slClaimPanel">
        <h2>Solve captcha to claim</h2>
        <x-trajectory-captcha name="sl-auth" />
        <button type="button" class="cta cta--primary" id="slClaim" style="justify-content:center;">
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
    const fp = window.SPCaptcha?.fingerprint || '';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const panel = document.getElementById('slClaimPanel');
    const claim = document.getElementById('slClaim');
    const msgEl = document.getElementById('slMsg');

    function showMsg(state, text) {
        msgEl.style.display = 'block';
        msgEl.className = state === 'ok' ? 'alert--ok' : 'alert--err';
        msgEl.textContent = text;
    }

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
