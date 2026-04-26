@extends('layouts.app')

@php
    use App\Models\Shortlink;
    use App\Models\ShortlinkClick;
    use Illuminate\Support\Carbon;
    $u = auth()->user();
    $today = Carbon::now()->startOfDay();
    $links = Shortlink::where('is_active', true)->orderByDesc('reward_sat')->limit(50)->get();
    $usedToday = ShortlinkClick::where('user_id', $u->id)
        ->where('status', 'verified')
        ->where('created_at', '>=', $today)
        ->selectRaw('shortlink_id, count(*) as used')
        ->groupBy('shortlink_id')
        ->pluck('used', 'shortlink_id');
@endphp

@push('head')
<style>
    .sl { max-width: 64rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 2rem; }
    .sl__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: .5rem 0 .25rem; }
    .sl__head h1 em { color: var(--amber-soft); font-style: italic; }
    .sl__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }
    .row-list { display: grid; gap: .75rem; }
    .row { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 1rem 1.25rem; display: grid; grid-template-columns: 1fr auto auto; gap: 1rem; align-items: center; }
    .row.exhausted { opacity: .55; }
    .row__title { font-size: var(--text-base); color: var(--text-primary); margin: 0; }
    .row__meta { font-family: var(--font-mono); font-size: .65rem; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); margin-top: .25rem; }
    .row__reward { font-family: var(--font-display); font-size: 1.25rem; color: var(--amber-soft); white-space: nowrap; }
    .row__reward small { font-family: var(--font-mono); font-size: .55em; color: var(--text-tertiary); margin-left: .15rem; }
    .row__cta { padding: .45rem .9rem; border-radius: var(--radius-md); background: var(--amber); color: #1a0e00; font-weight: 500; text-decoration: none; font-size: var(--text-sm); }
    .row__cta:hover { background: var(--amber-soft); color: #1a0e00; }
    .row__cta--disabled { background: var(--bg-elev); color: var(--text-tertiary); pointer-events: none; }
    .row__cta--in-flight { background: var(--bg-elev); color: var(--amber-soft); }
    @media (max-width: 540px) { .row { grid-template-columns: 1fr; } }

    .empty { background: var(--bg-panel); border: 1px dashed var(--border-strong); border-radius: var(--radius-lg); padding: 3rem 1.5rem; text-align: center; color: var(--text-tertiary); }
    .alert--ok { padding: .75rem 1rem; border-radius: var(--radius-md); background: rgba(52,211,153,.08); border: 1px solid rgba(52,211,153,.3); color: var(--mint); font-size: var(--text-sm); }
    .alert--err { padding: .75rem 1rem; border-radius: var(--radius-md); background: rgba(251,113,133,.08); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-size: var(--text-sm); }

    /* Captcha modal for completion */
    .modal-backdrop { position: fixed; inset: 0; background: rgba(7,9,15,.85); display: none; align-items: center; justify-content: center; padding: 1.5rem; z-index: 100; }
    .modal-backdrop.active { display: flex; }
    .modal { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem; max-width: 32rem; width: 100%; display: grid; gap: 1rem; }
    .modal h2 { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0; font-weight: 500; }
    .modal__title { font-family: var(--font-display); font-size: 1.5rem; line-height: 1.1; color: var(--text-primary); font-weight: 400; margin: 0; }
</style>
@endpush

@section('content')
<section class="sl">
    <header class="sl__head">
        <span class="meta">/ shortlinks</span>
        <h1>Quick <em>clicks</em>.</h1>
        <p style="color: var(--text-secondary); margin: 0;">Open the link, hold for the listed seconds, solve a captcha, get paid. Faster than PTC, smaller rewards.</p>
    </header>

    <div id="slMsg" style="display:none;"></div>

    @if ($links->isEmpty())
        <div class="empty">
            <h2 style="font-family: var(--font-display); font-size: 1.5rem; color: var(--text-secondary); font-weight: 400; margin: 0 0 .5rem;">No shortlinks yet.</h2>
            <p>Inventory empty — check back shortly.</p>
        </div>
    @else
        <div class="row-list">
            @foreach ($links as $l)
                @php
                    $left = max(0, (int) $l->daily_limit_per_user - (int) ($usedToday[$l->id] ?? 0));
                    $exhausted = $left <= 0;
                @endphp
                <article class="row {{ $exhausted ? 'exhausted' : '' }}" data-link-id="{{ $l->id }}" data-target="{{ $l->target_url }}" data-hold="{{ $l->hold_seconds }}" data-reward="{{ $l->reward_sat }}">
                    <div>
                        <h3 class="row__title">{{ $l->title }}</h3>
                        <div class="row__meta">{{ $l->source }} · {{ $l->hold_seconds }}s hold · {{ $left }}/{{ $l->daily_limit_per_user }} left today</div>
                    </div>
                    <div class="row__reward">{{ number_format($l->reward_sat) }}<small>sat</small></div>
                    <div>
                        @if ($exhausted)
                            <span class="row__cta row__cta--disabled">Done today</span>
                        @else
                            <button type="button" class="row__cta sl-go">Open &amp; hold →</button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>

<div class="modal-backdrop" id="slModal">
    <div class="modal">
        <h2>Complete shortlink</h2>
        <p class="modal__title" id="slModalTitle">—</p>
        <p style="color: var(--text-secondary); margin: 0; font-size: var(--text-sm);" id="slModalSub">Hold the tab for <span id="slLeft">—</span>s, then solve the captcha to claim.</p>
        <x-trajectory-captcha name="sl" />
        <button type="button" class="cta cta--primary" id="slClaim" disabled style="justify-content:center;">
            Claim reward <span class="cta__arrow">→</span>
        </button>
        <button type="button" class="cta cta--ghost" id="slCancel" style="justify-content:center;">Cancel</button>
    </div>
</div>
@endsection

@push('body')
<script>
(() => {
    const fp = window.SPCaptcha?.fingerprint || '';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const modal = document.getElementById('slModal');
    const modalTitle = document.getElementById('slModalTitle');
    const modalSub = document.getElementById('slModalSub');
    const leftEl = document.getElementById('slLeft');
    const claim = document.getElementById('slClaim');
    const cancel = document.getElementById('slCancel');
    const msgEl = document.getElementById('slMsg');

    function showMsg(state, text) {
        msgEl.style.display = 'block';
        msgEl.className = state === 'ok' ? 'alert--ok' : 'alert--err';
        msgEl.textContent = text;
    }

    let active = null;

    document.querySelectorAll('.sl-go').forEach(btn => btn.addEventListener('click', async (e) => {
        const row = e.target.closest('.row');
        const linkId = row.dataset.linkId;
        const target = row.dataset.target;
        const hold = parseInt(row.dataset.hold, 10);

        try {
            const r = await fetch(`/api/shortlinks/${linkId}/start`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-SP-Fingerprint': fp },
                credentials: 'same-origin',
            });
            const data = await r.json();
            if (!r.ok) { showMsg('err', data?.error || 'Could not start click.'); return; }
            active = { clickId: data.click_id, epochToken: data.epoch_token, hold };
            window.open(target, '_blank', 'noopener,noreferrer');
            modal.classList.add('active');
            modalTitle.textContent = row.querySelector('.row__title').textContent;
            row.querySelector('.sl-go').classList.add('row__cta--in-flight');
            row.querySelector('.sl-go').textContent = 'Tab open · holding…';

            let remaining = hold;
            leftEl.textContent = remaining;
            const id = setInterval(() => {
                remaining = Math.max(0, remaining - 1);
                leftEl.textContent = remaining;
                if (remaining === 0) {
                    clearInterval(id);
                    claim.disabled = false;
                    // Fresh captcha at the moment user can solve — gives full 25 s window.
                    const cap = modal.querySelector('[data-trajectory-captcha]');
                    if (cap?.spReset) cap.spReset();
                    modalSub.innerHTML = '<strong style="color: var(--mint);">Hold complete</strong> — solve the captcha to claim.';
                }
            }, 1000);
        } catch (err) {
            showMsg('err', 'Network error starting click.');
        }
    }));

    cancel.addEventListener('click', () => {
        modal.classList.remove('active');
        active = null;
        showMsg('err', 'Click cancelled.');
    });

    claim.addEventListener('click', async () => {
        if (!active) return;
        const cap = modal.querySelector('[data-trajectory-captcha]');
        const state = cap?.spGetState ? cap.spGetState() : { challengeId: '', points: '', isReady: false };
        if (!state.isReady) { showMsg('err', 'Solve the captcha first.'); return; }
        const challengeId = state.challengeId;
        const points = state.points;
        try {
            const v = await fetch('/api/captcha/verify', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-SP-Fingerprint': fp },
                body: JSON.stringify({ challengeId, points: JSON.parse(points) }),
                credentials: 'same-origin',
            });
            const vd = await v.json();
            if (!v.ok || !vd.passed) { showMsg('err', `Captcha rejected (${vd.reason || 'unknown'}).`); return; }

            const c = await fetch(`/api/shortlinks/${active.clickId}/complete`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-SP-Fingerprint': fp },
                body: JSON.stringify({ epoch_token: active.epochToken, captcha_challenge_id: challengeId }),
                credentials: 'same-origin',
            });
            const cd = await c.json();
            if (!c.ok) { showMsg('err', cd?.error || 'Could not claim.'); return; }
            modal.classList.remove('active');
            showMsg('ok', `Credited ${cd.reward_sat} sat. Pick another shortlink to keep earning.`);
            setTimeout(() => location.reload(), 1500);
        } catch (e) {
            showMsg('err', 'Network error during claim.');
        }
    });
})();
</script>
@endpush
