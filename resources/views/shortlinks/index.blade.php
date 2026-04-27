@extends('layouts.app')

@php
    use App\Models\Shortlink;
    use App\Models\ShortlinkClick;
    use App\Offerwall\OfferwallMerge;
    use Illuminate\Support\Carbon;
    $u = auth()->user();
    $today = Carbon::now()->startOfDay();
    // Only surface rotation-enabled internal entries (provider_name + source_url
    // both set). Static shortlinks are no longer supported by the /shortlinks
    // surface — operator policy is "shortener-API rotation OR BitcoTask
    // offerwall, never static". BitcoTask offers come in via OfferwallMerge
    // below.
    $links = Shortlink::query()
        ->where('is_active', true)
        ->whereNotNull('provider_name')
        ->whereNotNull('source_url')
        ->orderByDesc('reward_sat')
        ->limit(50)
        ->get();
    $usedToday = ShortlinkClick::where('user_id', $u->id)
        ->where('status', 'verified')
        ->where('created_at', '>=', $today)
        ->selectRaw('shortlink_id, count(*) as used')
        ->groupBy('shortlink_id')
        ->pluck('used', 'shortlink_id');

    // External per-user offers (BitcoTasks today). Empty when no per-user
    // adapter is enabled or its API key is unset, so /shortlinks keeps
    // working on internal inventory alone — important because BitcoTasks
    // gates publisher-API access on a manual review.
    $externalLinks = app(OfferwallMerge::class)->fetchShortlinkFor($u, request()->ip() ?? '');
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
                <article class="row {{ $exhausted ? 'exhausted' : '' }}" data-link-id="{{ $l->id }}" data-hold="{{ $l->hold_seconds }}" data-reward="{{ $l->reward_sat }}">
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

    @if (! empty($externalLinks))
        <header class="sl__head" style="margin-top: 1rem;">
            <span class="meta">/ partner network</span>
            <h1 style="font-size: 2rem;">More <em>links</em>.</h1>
            <p style="color: var(--text-secondary); margin: 0;">External shortlinks from connected publishers. Reward is credited via the publisher's server callback once you complete the interstitial on their side.</p>
        </header>
        <div class="row-list">
            @foreach ($externalLinks as $offer)
                <article class="row">
                    <div>
                        <h3 class="row__title">{{ $offer->title }}</h3>
                        <div class="row__meta">{{ $offer->source }} · {{ $offer->durationSec }}s hold · {{ $offer->dailyLimitPerUser }}/day</div>
                    </div>
                    <div class="row__reward">{{ number_format($offer->rewardSat) }}<small>sat</small></div>
                    <div>
                        <a href="{{ $offer->targetUrl }}" target="_blank" rel="noopener noreferrer" class="row__cta">Open ↗</a>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection

@push('body')
<script>
(() => {
    const fp = window.SPCaptcha?.fingerprint || '';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const msgEl = document.getElementById('slMsg');

    function showMsg(state, text) {
        msgEl.style.display = 'block';
        msgEl.className = state === 'ok' ? 'alert--ok' : 'alert--err';
        msgEl.textContent = text;
    }

    document.querySelectorAll('.sl-go').forEach(btn => btn.addEventListener('click', async (e) => {
        const row = e.target.closest('.row');
        const linkId = row.dataset.linkId;
        btn.disabled = true;
        btn.classList.add('row__cta--in-flight');
        btn.textContent = 'Opening…';

        try {
            const r = await fetch(`/api/shortlinks/${linkId}/start`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-SP-Fingerprint': fp },
                credentials: 'same-origin',
            });
            const data = await r.json();
            if (!r.ok) {
                showMsg('err', data?.error || 'Could not start click.');
                btn.disabled = false;
                btn.classList.remove('row__cta--in-flight');
                btn.textContent = 'Open & hold →';
                return;
            }
            // Open the destination (rotated shortener URL) in a new tab so
            // the operator's affiliate revenue lands as expected — meanwhile
            // navigate the current tab to the per-click rotating auth URL,
            // where the hold timer + captcha + claim live.
            window.open(data.redirect_url, '_blank', 'noopener,noreferrer');
            location.href = `/shortlinks/auth/${encodeURIComponent(data.epoch_token)}`;
        } catch (err) {
            showMsg('err', 'Network error starting click.');
            btn.disabled = false;
            btn.classList.remove('row__cta--in-flight');
            btn.textContent = 'Open & hold →';
        }
    }));
})();
</script>
@endpush
