@extends('layouts.app')

@php
    use App\Models\PtcView;
    $delivered = (int) ($ad->total_views_purchased - $ad->views_remaining);
    $verified = PtcView::where('ptc_ad_id', $ad->id)->where('status', 'verified')->count();
    $attempts = PtcView::where('ptc_ad_id', $ad->id)->count();
    $progress = $ad->total_views_purchased > 0
        ? min(100, max(0, 100 * $delivered / $ad->total_views_purchased))
        : 0;
@endphp

@push('head')
<style>
    .adv { max-width: 56rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 2rem; }
    .adv__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: .5rem 0 .25rem; }
    .adv__head h1 em { color: var(--amber-soft); font-style: italic; }
    .adv__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }
    .alert--ok { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(52,211,153,.08); border: 1px solid rgba(52,211,153,.3); color: var(--mint); font-size: var(--text-sm); }

    .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: var(--border-subtle); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); overflow: hidden; }
    @media (max-width: 720px) { .stats { grid-template-columns: repeat(2, 1fr); } }
    .stats__cell { background: var(--bg-panel); padding: 1.25rem 1rem; }
    .stats__num { font-family: var(--font-display); font-size: 1.75rem; line-height: 1; color: var(--amber-soft); letter-spacing: -.01em; }
    .stats__num small { font-family: var(--font-mono); font-size: .55em; color: var(--text-tertiary); margin-left: .25rem; }
    .stats__label { margin-top: .5rem; font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }

    .panel { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem; display: grid; gap: 1rem; }
    .panel h2 { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0; font-weight: 500; }

    .progress { display: grid; gap: .35rem; }
    .progress .nums { display: flex; justify-content: space-between; font-family: var(--font-mono); font-size: var(--text-sm); color: var(--text-secondary); }
    .progress .bar { width: 100%; height: 6px; background: var(--bg-elev-2); border-radius: 999px; overflow: hidden; }
    .progress .bar > div { height: 100%; background: var(--amber-soft); }

    .kv { display: grid; gap: .25rem; font-size: var(--text-sm); }
    .kv li { display: grid; grid-template-columns: 9rem 1fr; gap: 1rem; padding: .5rem 0; border-top: 1px solid var(--border-faint); align-items: baseline; }
    .kv li:first-child { border-top: 0; padding-top: 0; }
    .kv .label { font-family: var(--font-mono); font-size: .68rem; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); }
    .kv .value { color: var(--text-secondary); word-break: break-all; }
    .kv .value strong { color: var(--text-primary); font-weight: 500; }

    .status-badge { display: inline-block; padding: .15rem .55rem; border-radius: 4px; font-family: var(--font-mono); font-size: .7rem; text-transform: uppercase; letter-spacing: .1em; }
    .status-approved   { background: rgba(52,211,153,.12); color: var(--mint); }
    .status-pending_review { background: rgba(252,211,77,.12); color: var(--amber-soft); }
    .status-completed  { background: rgba(103,232,249,.12); color: var(--cyan); }
    .status-rejected   { background: rgba(251,113,133,.12); color: var(--rose); }
    .status-paused     { background: rgba(170,180,194,.12); color: var(--text-secondary); }
</style>
@endpush

@section('content')
<section class="adv">
    @if (session('status')) <div class="alert--ok">{{ session('status') }}</div> @endif

    <header class="adv__head">
        <span class="meta">/ advertise · campaign #{{ $ad->id }}</span>
        <h1>{{ $ad->title }} <span class="status-badge status-{{ $ad->status }}">{{ str_replace('_', ' ', $ad->status) }}</span></h1>
        @if ($ad->status === 'rejected' && $ad->rejection_reason)
            <p style="color: var(--rose); margin: .5rem 0 0; font-size: var(--text-sm);">Rejected: {{ $ad->rejection_reason }} (full refund issued).</p>
        @endif
        @if (! in_array($ad->status, ['rejected', 'completed'], true))
            <p style="margin: .75rem 0 0;">
                <a href="{{ route('advertise.edit', ['id' => $ad->id]) }}"
                   style="display:inline-flex; align-items:center; gap:.4rem; padding:.45rem .9rem; border-radius: var(--radius-md); background: var(--bg-elev); border: 1px solid var(--border-strong); color: var(--text-primary); text-decoration: none; font-size: var(--text-sm);">
                    Edit campaign <span aria-hidden="true">→</span>
                </a>
            </p>
        @endif
    </header>

    <div class="stats">
        <div class="stats__cell">
            <div class="stats__num">{{ number_format($delivered) }}<small>/{{ number_format($ad->total_views_purchased) }}</small></div>
            <div class="stats__label">Views delivered</div>
        </div>
        <div class="stats__cell">
            <div class="stats__num">{{ number_format($verified) }}<small>verified</small></div>
            <div class="stats__label">Out of {{ number_format($attempts) }} attempts</div>
        </div>
        <div class="stats__cell">
            <div class="stats__num">{{ number_format($delivered * $ad->reward_sat) }}<small>sat</small></div>
            <div class="stats__label">Paid to viewers so far</div>
        </div>
        <div class="stats__cell">
            <div class="stats__num">{{ number_format($ad->views_remaining * $ad->cost_per_view_sat) }}<small>sat</small></div>
            <div class="stats__label">Budget remaining</div>
        </div>
    </div>

    <div class="panel">
        <h2>Campaign progress</h2>
        <div class="progress">
            <div class="nums">
                <span>{{ number_format($delivered) }} delivered</span>
                <span>{{ number_format($ad->views_remaining) }} remaining</span>
            </div>
            <div class="bar"><div style="width: {{ number_format($progress, 1) }}%;"></div></div>
        </div>
    </div>

    <div class="panel">
        <h2>Settings</h2>
        <ul class="kv" style="list-style:none; padding:0; margin:0;">
            <li><span class="label">Target URL</span> <span class="value"><a href="{{ $ad->target_url }}" target="_blank" rel="noopener noreferrer" style="color: var(--amber-soft); text-decoration: underline;">{{ $ad->target_url }}</a></span></li>
            <li><span class="label">Display mode</span> <span class="value">{{ $ad->display_mode === 'iframe' ? 'Inline iframe (embedded in viewer page)' : 'New tab (opens in a separate window)' }}</span></li>
            <li><span class="label">Reward / view</span> <span class="value"><strong>{{ number_format($ad->reward_sat) }} sat</strong> to viewer</span></li>
            <li><span class="label">Cost / view</span> <span class="value">{{ number_format($ad->cost_per_view_sat) }} sat (incl. {{ (int) config('satpeek.ads.commission_pct') }}% fee)</span></li>
            <li><span class="label">Watch duration</span> <span class="value">{{ $ad->duration_sec }}s</span></li>
            <li><span class="label">Daily limit</span> <span class="value">{{ $ad->daily_limit_per_user }} per viewer</span></li>
            <li><span class="label">Total budget</span> <span class="value"><strong>{{ number_format($ad->total_cost_sat) }} sat</strong></span></li>
            <li><span class="label">Submitted</span> <span class="value">{{ $ad->created_at->diffForHumans() }}</span></li>
            @if ($ad->approved_at)
                <li><span class="label">Approved</span> <span class="value">{{ $ad->approved_at->diffForHumans() }}</span></li>
            @endif
        </ul>
    </div>

    <p style="font-size: var(--text-xs); color: var(--text-tertiary); text-align: center;">
        ← <a href="{{ route('advertise.index') }}" style="color: var(--text-secondary); text-decoration: underline;">All campaigns</a>
    </p>
</section>
@endsection
