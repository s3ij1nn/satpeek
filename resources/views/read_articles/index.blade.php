@extends('layouts.app')

@push('head')
<style>
    .ra { max-width: 64rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 2rem; }
    .ra__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: .5rem 0 .25rem; }
    .ra__head h1 em { color: var(--amber-soft); font-style: italic; }
    .ra__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }
    .ad-list { display: grid; gap: 1rem; }
    .ad { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.25rem 1.5rem; display: grid; grid-template-columns: 1fr auto auto; gap: 1.25rem; align-items: center; transition: border-color var(--dur-fast) var(--ease-out-expo); }
    .ad:hover { border-color: var(--border-strong); }
    .ad__title { font-size: var(--text-lg); color: var(--text-primary); margin: 0 0 .25rem; }
    .ad__desc { color: var(--text-secondary); font-size: var(--text-sm); margin: 0; line-height: 1.5; max-width: 28rem; }
    .ad__source { font-family: var(--font-mono); font-size: .65rem; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); margin-top: .35rem; }
    .ad__reward { font-family: var(--font-display); font-size: 1.5rem; color: var(--amber-soft); white-space: nowrap; }
    .ad__reward small { font-family: var(--font-mono); font-size: .55em; color: var(--text-tertiary); margin-left: .15rem; }
    .ad__meta { display: grid; gap: .15rem; text-align: right; font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); white-space: nowrap; }
    .ad__cta { display: inline-flex; align-items: center; gap: .4rem; padding: .55rem 1rem; border-radius: var(--radius-md); background: var(--amber); color: #1a0e00; font-weight: 500; text-decoration: none; font-size: var(--text-sm); }
    .ad__cta:hover { background: var(--amber-soft); color: #1a0e00; }
    @media (max-width: 640px) {
        .ad { grid-template-columns: 1fr; gap: .75rem; }
        .ad__meta { text-align: left; }
    }
    .empty { background: var(--bg-panel); border: 1px dashed var(--border-strong); border-radius: var(--radius-lg); padding: 3rem 1.5rem; text-align: center; color: var(--text-tertiary); }
    .empty h2 { font-family: var(--font-display); font-size: 1.5rem; color: var(--text-secondary); font-weight: 400; margin: 0 0 .5rem; }
</style>
@endpush

@section('content')
<section class="ra">
    <header class="ra__head">
        <span class="meta">/ read articles</span>
        <h1>Read &amp; <em>earn</em>.</h1>
        <p style="color: var(--text-secondary); margin: 0;">Quick read-through tasks supplied by connected publishers. Reward credits to your balance once the publisher confirms the read on their side.</p>
    </header>

    @if (! $hasProvider)
        <div class="empty">
            <h2>No read-article partners connected.</h2>
            <p>This surface lights up automatically once an offerwall publisher with a read-article inventory is enabled in <code>OFFERWALLS_ENABLED</code>.</p>
        </div>
    @elseif (empty($offers))
        <div class="empty">
            <h2>No tasks available right now.</h2>
            <p>The partner returned an empty list for your account &amp; IP. Check back shortly.</p>
        </div>
    @else
        <div class="ad-list">
            @foreach ($offers as $offer)
                <article class="ad">
                    <div>
                        <h3 class="ad__title">{{ $offer->title }}</h3>
                        @if ($offer->description)
                            <p class="ad__desc">{{ $offer->description }}</p>
                        @endif
                        <p class="ad__source">{{ $offer->source }} · {{ $offer->durationSec }}s · {{ $offer->dailyLimitPerUser }}/day</p>
                    </div>
                    <div class="ad__meta">
                        <div class="ad__reward">{{ number_format($offer->rewardSat) }}<small>sat</small></div>
                        <div>via {{ $offer->source }}</div>
                    </div>
                    <div>
                        <a href="{{ $offer->targetUrl }}" target="_blank" rel="noopener noreferrer" class="ad__cta">Read <span aria-hidden="true">↗</span></a>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
