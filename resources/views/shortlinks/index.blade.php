@extends('layouts.app')

@php
    use App\Http\Controllers\Api\ShortlinkController;
    use App\Models\ShortlinkClick;
    use App\Offerwall\OfferwallMerge;
    use Illuminate\Support\Carbon;
    $u = auth()->user();
    $today = Carbon::now()->startOfDay();
    // Provider-keyed shortlinks: one row per active shortener (btcut /
    // cuty / exe / shrtfly / ouo) with a configured API token. The user
    // picks a provider; SatPeek mints a fresh /shortlinks/auth/{token},
    // shortens it through the chosen provider, and pays when the user
    // completes the provider's interstitial and lands back on the token URL.
    $providers = ShortlinkController::enabledProviders();
    // Per-(user, provider_name) verified-clicks-today counter for the
    // "N/M left today" display. Mirrors the daily_limit check in
    // ShortlinkController::start.
    $usedTodayByProvider = ShortlinkClick::where('user_id', $u->id)
        ->where('status', 'verified')
        ->where('created_at', '>=', $today)
        ->whereNotNull('provider_name')
        ->selectRaw('provider_name, count(*) as used')
        ->groupBy('provider_name')
        ->pluck('used', 'provider_name');

    // External per-user offers (BitcoTasks today). Empty when no per-user
    // adapter is enabled or its API key is unset, so /shortlinks keeps
    // working on internal providers alone — important because BitcoTasks
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
    .row { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 1rem 1.25rem; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 1rem 1.25rem; align-items: start; }
    .row.exhausted { opacity: .55; }
    .row__title { font-size: var(--text-base); color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .row__meta { font-family: var(--font-mono); font-size: .65rem; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); margin-top: .35rem; }
    .row__meta strong { color: var(--text-secondary); }
    .row__reward { font-family: var(--font-display); font-size: 1.25rem; color: var(--amber-soft); white-space: nowrap; text-align: right; }
    .row__reward small { font-family: var(--font-mono); font-size: .55em; color: var(--text-tertiary); margin-left: .15rem; }

    /* Multi-button visit chip strip — one chip per remaining daily view,
       firefaucet-style. Each chip is its own click → fresh ShortlinkClick. */
    .row__chips { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: .35rem; padding-top: .25rem; }
    .chip { min-width: 2.25rem; padding: .35rem .55rem; border-radius: var(--radius-sm, 6px); background: var(--bg-elev); border: 1px solid var(--border-subtle); color: var(--amber-soft); font-family: var(--font-mono); font-size: var(--text-xs); cursor: pointer; transition: background .12s ease, color .12s ease, border-color .12s ease; text-align: center; line-height: 1; }
    .chip:hover { background: var(--amber); color: #1a0e00; border-color: var(--amber); }
    .chip[disabled] { background: var(--bg-elev); color: var(--text-tertiary); border-color: var(--border-subtle); cursor: not-allowed; opacity: .55; }
    .chip--in-flight { background: var(--bg-elev); color: var(--text-tertiary); cursor: progress; }

    .row__badge { display: inline-flex; align-items: center; padding: .15rem .4rem; border-radius: var(--radius-sm, 4px); background: rgba(251,113,133,.12); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-family: var(--font-mono); font-size: .55rem; text-transform: uppercase; letter-spacing: .14em; }

    @media (max-width: 540px) { .row { grid-template-columns: 1fr; } .row__reward { text-align: left; } }

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
        <p style="color: var(--text-secondary); margin: 0;">Click a number to open that shortener. Complete the publisher's interstitial (the timer + skip-ad button on their page), and you'll be sent back here automatically to solve a captcha and claim. Skipping the shortener doesn't credit anything.</p>
    </header>

    <div id="slMsg" style="display:none;"></div>

    @if ($providers->isEmpty())
        <div class="empty">
            <h2 style="font-family: var(--font-display); font-size: 1.5rem; color: var(--text-secondary); font-weight: 400; margin: 0 0 .5rem;">No shortlinks available right now.</h2>
            <p>Check back shortly — new providers are added regularly.</p>
        </div>
    @else
        <div class="row-list">
            @foreach ($providers as $p)
                @php
                    $used = (int) ($usedTodayByProvider[$p->name] ?? 0);
                    $limit = (int) $p->daily_limit_per_user;
                    $left = max(0, $limit - $used);
                    $exhausted = $left <= 0;
                    $label = $p->label ?: $p->name;
                @endphp
                <article class="row {{ $exhausted ? 'exhausted' : '' }}" data-provider="{{ $p->name }}" data-hold="{{ $p->hold_seconds }}" data-reward="{{ $p->reward_sat }}">
                    <div>
                        <h3 class="row__title">{{ $label }}</h3>
                        <div class="row__meta">Views Left: <strong>{{ $left }}/{{ $limit }}</strong> · {{ $p->hold_seconds }}s hold after return</div>
                    </div>
                    <div class="row__reward">+{{ number_format($p->reward_sat) }}<small>sat</small></div>
                    <div class="row__chips">
                        @if ($exhausted)
                            <span class="chip" disabled>Done today</span>
                        @else
                            {{-- One numbered chip per remaining daily view —
                                 firefaucet-style. Each chip mints a fresh
                                 ShortlinkClick when clicked; the position
                                 number is just an affordance, not part of
                                 server state. --}}
                            @for ($i = 1; $i <= $left; $i++)
                                <button type="button" class="chip sl-go" data-idx="{{ $i }}">{{ $i }}</button>
                            @endfor
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
                        <div class="row__meta">via <strong style="color: var(--amber-soft);">{{ $offer->source }}</strong> · {{ $offer->durationSec }}s hold · {{ $offer->dailyLimitPerUser }}/day</div>
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
        const provider = row.dataset.provider;
        const originalLabel = btn.textContent;
        btn.disabled = true;
        btn.classList.add('chip--in-flight');
        btn.textContent = '…';

        try {
            const r = await fetch(`/api/shortlinks/start/${encodeURIComponent(provider)}`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-SP-Fingerprint': fp },
                credentials: 'same-origin',
            });
            const data = await r.json();
            if (!r.ok) {
                showMsg('err', data?.message || data?.error || 'Could not start click.');
                btn.disabled = false;
                btn.classList.remove('chip--in-flight');
                btn.textContent = originalLabel;
                return;
            }
            // Same-tab navigation to the shortener URL. Critical for fairness:
            // if we opened the shortener in a new tab and pushed the auth
            // page to the current tab, the user could close the new tab
            // and still hit the claim flow without ever crossing the
            // shortener (= operator gets no ad revenue, user gets free
            // sats). Forcing same-tab means the only path back to
            // /shortlinks/auth/{token} is the shortener's own redirect
            // after its interstitial runs.
            location.href = data.redirect_url;
        } catch (err) {
            showMsg('err', 'Network error starting click.');
            btn.disabled = false;
            btn.classList.remove('chip--in-flight');
            btn.textContent = originalLabel;
        }
    }));
})();
</script>
@endpush
