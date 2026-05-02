@extends('layouts.app')

@push('head')
<style>
    .ia-read { max-width: 44rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 1.5rem; }
    .ia-read__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: .5rem 0 .25rem; }
    .ia-read__head h1 em { color: var(--amber-soft); font-style: italic; }
    .ia-read__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }
    .ia-read__source { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); }

    .panel { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem; display: grid; gap: 1rem; }
    .panel h2 { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0; font-weight: 500; }
    .panel.locked { opacity: .55; pointer-events: none; }

    .body { color: var(--text-primary); font-size: var(--text-base); line-height: 1.7; }
    .body h1, .body h2, .body h3 { font-family: var(--font-display); font-weight: 500; margin: 1.5rem 0 .5rem; }
    .body h1 { font-size: 1.75rem; }
    .body h2 { font-size: 1.4rem; }
    .body h3 { font-size: 1.15rem; }
    .body p { margin: .85rem 0; }
    .body ul, .body ol { padding-left: 1.5rem; margin: .85rem 0; }
    .body li { margin: .25rem 0; }
    .body code { background: var(--bg-elev); padding: .1rem .35rem; border-radius: 4px; font-size: .9em; }
    .body pre { background: var(--bg-elev); padding: 1rem; border-radius: var(--radius-md); overflow-x: auto; font-size: .85em; }
    .body a { color: var(--amber-soft); text-decoration: underline; }
    .body blockquote { border-left: 3px solid var(--amber-soft); padding-left: 1rem; color: var(--text-secondary); margin: 1rem 0; }

    .countdown { display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: center; padding: 1rem 1.25rem; background: var(--bg-elev); border-radius: var(--radius-md); position: sticky; top: 1rem; z-index: 10; box-shadow: 0 4px 12px rgba(0,0,0,.25); }
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
<section class="ia-read">
    <header class="ia-read__head">
        <span class="meta">/ read articles · internal</span>
        <h1>{{ $article->title }}</h1>
        @if ($article->source_attribution)
            <p class="ia-read__source">— {{ $article->source_attribution }}</p>
        @endif
        <span class="reward-pill" style="margin-top:.5rem;">+{{ number_format($reward_sat) }}<small style="color: var(--text-tertiary);">sat</small></span>
    </header>

    <div id="iaMsg" style="display:none;"></div>

    <div class="panel">
        <h2>Reading time</h2>
        <div class="countdown">
            <div>
                <div class="countdown__label">Time remaining</div>
                <div class="countdown__bar"><div id="iaBar" style="width: 0%;"></div></div>
            </div>
            <div class="countdown__num"><span id="iaLeft">{{ $remaining_sec }}</span><small>sec</small></div>
        </div>
    </div>

    <article class="panel">
        <h2>Article</h2>
        <div class="body">{!! $body_html !!}</div>
    </article>

    <div class="panel locked" id="iaClaimPanel">
        <h2>Solve captcha to claim</h2>
        <x-trajectory-captcha name="ia-read" />
        <button type="button" class="cta cta--primary" id="iaClaim" disabled style="justify-content:center;">
            Claim {{ number_format($reward_sat) }} sat <span class="cta__arrow">→</span>
        </button>
    </div>

    <p style="font-size: var(--text-xs); color: var(--text-tertiary); text-align: center; margin: 0;">
        ← <a href="{{ route('read_articles.index') }}" style="color: var(--text-secondary); text-decoration: underline;">Back to read articles</a>
    </p>
</section>
@endsection

@push('body')
<script>
(() => {
    const token = @json($view->epoch_token);
    const total = {{ (int) $read_seconds }};
    const fp = window.SPCaptcha?.fingerprint || '';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const leftEl = document.getElementById('iaLeft');
    const barEl = document.getElementById('iaBar');
    const panel = document.getElementById('iaClaimPanel');
    const claim = document.getElementById('iaClaim');
    const msgEl = document.getElementById('iaMsg');

    let remaining = {{ (int) $remaining_sec }};

    function showMsg(state, text) {
        msgEl.style.display = 'block';
        msgEl.className = state === 'ok' ? 'alert--ok' : 'alert--err';
        msgEl.textContent = text;
    }

    function unlock() {
        panel.classList.remove('locked');
        claim.disabled = false;
        const cap = panel.querySelector('[data-trajectory-captcha]');
        if (cap?.spReset) cap.spReset();
    }

    function tickInit() {
        const elapsed = Math.max(0, total - remaining);
        barEl.style.width = (elapsed / total * 100).toFixed(1) + '%';
        if (remaining === 0) { unlock(); return; }
        const id = setInterval(() => {
            remaining = Math.max(0, remaining - 1);
            leftEl.textContent = remaining;
            barEl.style.width = ((total - remaining) / total * 100).toFixed(1) + '%';
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

            const c = await fetch(`/api/internal-articles/auth/${encodeURIComponent(token)}/complete`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-SP-Fingerprint': fp },
                body: JSON.stringify({ epoch_token: token, captcha_challenge_id: state.challengeId }),
                credentials: 'same-origin',
            });
            const cd = await c.json();
            if (!c.ok) { showMsg('err', cd?.error || 'Could not claim.'); return; }
            showMsg('ok', `Credited ${cd.reward_sat} sat. Returning to read articles…`);
            setTimeout(() => location.href = '{{ route('read_articles.index') }}', 1400);
        } catch (e) {
            showMsg('err', 'Network error during claim.');
        }
    });
})();
</script>
@endpush
