@extends('layouts.app')

@push('head')
<style>
    .adv { max-width: 48rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 1.5rem; }
    .adv__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: .5rem 0 .25rem; }
    .adv__head h1 em { color: var(--amber-soft); font-style: italic; }
    .adv__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }

    .form-card { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 2rem; display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
    .form-card > * { min-width: 0; }
    .field { display: grid; gap: .4rem; }
    .field label { font-family: var(--font-mono); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .14em; color: var(--text-tertiary); }
    .field input, .field textarea { background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: .75rem .875rem; color: var(--text-primary); font: inherit; }
    .field textarea { min-height: 4rem; resize: vertical; font-family: inherit; }
    .field input:focus, .field textarea:focus { outline: 0; border-color: var(--amber); box-shadow: 0 0 0 3px var(--amber-glow); }
    .field__hint { font-size: var(--text-xs); color: var(--text-tertiary); }
    .field__error { font-size: var(--text-xs); color: var(--rose); }
    .field-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
    @media (max-width: 640px) { .field-row { grid-template-columns: 1fr; } }

    /* Two-card radio for display_mode (iframe vs new tab). */
    .mode-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
    @media (max-width: 640px) { .mode-grid { grid-template-columns: 1fr; } }
    .mode-card { display: block; cursor: pointer; padding: 1rem 1.125rem; background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: var(--radius-md); transition: border-color var(--dur-fast) var(--ease-out-expo), background var(--dur-fast) var(--ease-out-expo); }
    .mode-card:hover { border-color: var(--border-strong); }
    .mode-card input { position: absolute; opacity: 0; pointer-events: none; }
    .mode-card:has(input:checked) { border-color: var(--amber); background: rgba(251,191,36,.06); box-shadow: 0 0 0 3px var(--amber-glow); }
    .mode-card__title { display: block; font-size: var(--text-sm); color: var(--text-primary); font-weight: 500; margin-bottom: .25rem; }
    .mode-card__desc { display: block; font-size: var(--text-xs); color: var(--text-tertiary); line-height: 1.5; }

    .summary { background: var(--bg-elev); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 1rem 1.25rem; }
    .summary h2 { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0 0 .75rem; font-weight: 500; }
    .summary table { width: 100%; border-collapse: collapse; font-family: var(--font-mono); font-size: var(--text-sm); color: var(--text-secondary); }
    .summary td { padding: .35rem 0; }
    .summary td.lbl { color: var(--text-tertiary); }
    .summary td.val { text-align: right; color: var(--text-primary); }
    .summary tr.total td { padding-top: .65rem; border-top: 1px solid var(--border-subtle); font-weight: 600; }
    .summary tr.total td.val { color: var(--amber-soft); font-size: 1rem; }
    .summary .neg { color: var(--rose); }

    .alert--err { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.08); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-size: var(--text-sm); }
    .balance-pill { display: inline-flex; align-items: baseline; gap: .35rem; padding: .35rem .75rem; background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: 999px; font-family: var(--font-mono); font-size: var(--text-sm); color: var(--text-primary); }
</style>
@endpush

@section('content')
<section class="adv">
    <header class="adv__head">
        <span class="meta">/ advertise · new campaign</span>
        <h1>Run an <em>ad</em>.</h1>
        <p style="color: var(--text-secondary); margin: 0;">Drop a target URL, set a per-view reward, choose how many views you want to buy. Cost is locked in upfront from your balance.</p>
        <div style="margin-top: 1rem;"><span class="balance-pill">{{ number_format($balance) }} <small style="color: var(--text-tertiary);">sat available</small></span></div>
    </header>

    @if ($errors->any())
        <div class="alert--err">
            @foreach ($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    <form class="form-card" method="POST" action="{{ route('advertise.store') }}" id="adForm" novalidate>
        @csrf
        <div class="field">
            <label for="title">Title (visible to viewers)</label>
            <input id="title" name="title" type="text" required maxlength="200"
                   placeholder="My affiliate offer" value="{{ old('title') }}">
        </div>
        <div class="field">
            <label for="description">Description (optional)</label>
            <textarea id="description" name="description" maxlength="500"
                      placeholder="A short pitch — what's behind the link?">{{ old('description') }}</textarea>
        </div>
        <div class="field">
            <label for="target_url">Target URL</label>
            <input id="target_url" name="target_url" type="url" required maxlength="500"
                   placeholder="https://your-affiliate-link.com/?ref=xxxx" value="{{ old('target_url') }}">
            <span class="field__hint">No malware, no phishing, no offers requiring captcha-solving services. Every submission is reviewed before going live.</span>
        </div>

        @php $mode = old('display_mode', 'window'); @endphp
        <div class="field">
            <label>How should viewers see this ad?</label>
            <div class="mode-grid">
                <label class="mode-card">
                    <input type="radio" name="display_mode" value="window" @checked($mode === 'window')>
                    <span class="mode-card__title">New tab (recommended)</span>
                    <span class="mode-card__desc">Opens your URL in a separate tab when the viewer clicks. Works for every site, even ones that block embedding or top-frame-redirect.</span>
                </label>
                <label class="mode-card">
                    <input type="radio" name="display_mode" value="iframe" @checked($mode === 'iframe')>
                    <span class="mode-card__title">Inline iframe</span>
                    <span class="mode-card__desc">Embeds your URL directly inside the viewer page. Pick this only if you control the destination and have confirmed it doesn't block iframes (no <code>X-Frame-Options</code> / CSP <code>frame-ancestors</code>).</span>
                </label>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="reward_sat">Reward / view (sat)</label>
                <input id="reward_sat" name="reward_sat" type="number"
                       min="{{ $cfg['reward_min_sat'] }}" max="{{ $cfg['reward_max_sat'] }}" step="1"
                       required value="{{ old('reward_sat', 5) }}">
                <span class="field__hint">{{ $cfg['reward_min_sat'] }}–{{ $cfg['reward_max_sat'] }} sat. Higher = faster fill.</span>
            </div>
            <div class="field">
                <label for="duration_sec">Watch duration (sec)</label>
                <input id="duration_sec" name="duration_sec" type="number"
                       min="{{ $cfg['duration_min_sec'] }}" max="{{ $cfg['duration_max_sec'] }}" step="1"
                       required value="{{ old('duration_sec', 15) }}">
                <span class="field__hint">{{ $cfg['duration_min_sec'] }}–{{ $cfg['duration_max_sec'] }}s.</span>
            </div>
            <div class="field">
                <label for="daily_limit_per_user">Daily limit / viewer</label>
                <input id="daily_limit_per_user" name="daily_limit_per_user" type="number"
                       min="1" max="10" step="1"
                       required value="{{ old('daily_limit_per_user', 3) }}">
                <span class="field__hint">How many times one viewer can claim it per day.</span>
            </div>
        </div>

        <div class="field">
            <label for="total_views_purchased">Total views to buy</label>
            <input id="total_views_purchased" name="total_views_purchased" type="number"
                   min="{{ $cfg['views_min'] }}" max="{{ $cfg['views_max'] }}" step="100"
                   required value="{{ old('total_views_purchased', 1000) }}">
            <span class="field__hint">{{ number_format($cfg['views_min']) }}–{{ number_format($cfg['views_max']) }}.</span>
        </div>

        <div class="field">
            <label for="submission_notes">Notes for the reviewer (optional)</label>
            <textarea id="submission_notes" name="submission_notes" maxlength="1000"
                      placeholder="e.g. legal disclaimer, target audience…">{{ old('submission_notes') }}</textarea>
        </div>

        <div class="summary">
            <h2>Cost estimate</h2>
            <table>
                <tr><td class="lbl">Reward to viewer</td><td class="val" id="sumReward">— sat</td></tr>
                <tr><td class="lbl">Platform fee ({{ $cfg['commission_pct'] }}%)</td><td class="val" id="sumFee">— sat</td></tr>
                <tr><td class="lbl">Cost per view</td><td class="val" id="sumCpv">— sat</td></tr>
                <tr><td class="lbl">Views</td><td class="val" id="sumViews">—</td></tr>
                <tr class="total"><td class="lbl">Total reserved from balance</td><td class="val" id="sumTotal">— sat</td></tr>
            </table>
        </div>

        <button type="submit" class="cta cta--primary" style="margin-top:.5rem; justify-content:center;">
            Submit for review <span class="cta__arrow">→</span>
        </button>
    </form>
</section>
@endsection

@push('body')
<script>
(() => {
    const pct = {{ (int) $cfg['commission_pct'] }};
    const balance = {{ (int) $balance }};
    const reward = document.getElementById('reward_sat');
    const views = document.getElementById('total_views_purchased');
    const sumReward = document.getElementById('sumReward');
    const sumFee = document.getElementById('sumFee');
    const sumCpv = document.getElementById('sumCpv');
    const sumViews = document.getElementById('sumViews');
    const sumTotal = document.getElementById('sumTotal');

    function fmt(n) { return Number(n).toLocaleString(); }
    function recompute() {
        const r = Math.max(0, parseInt(reward.value || '0', 10));
        const v = Math.max(0, parseInt(views.value || '0', 10));
        const cpv = Math.ceil(r * (100 + pct) / 100);
        const fee = cpv - r;
        const total = cpv * v;
        sumReward.textContent = fmt(r) + ' sat';
        sumFee.textContent = fmt(fee) + ' sat';
        sumCpv.textContent = fmt(cpv) + ' sat';
        sumViews.textContent = fmt(v);
        sumTotal.innerHTML = (total > balance ? `<span class="neg">${fmt(total)} sat (insufficient)</span>` : fmt(total) + ' sat');
    }
    reward.addEventListener('input', recompute);
    views.addEventListener('input', recompute);
    recompute();
})();
</script>
@endpush
