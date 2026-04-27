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
    .field__locked { background: var(--bg-elev); border: 1px dashed var(--border-strong); border-radius: var(--radius-md); padding: .65rem .875rem; color: var(--text-tertiary); font-family: var(--font-mono); font-size: var(--text-sm); word-break: break-all; }

    .mode-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
    @media (max-width: 640px) { .mode-grid { grid-template-columns: 1fr; } }
    .mode-card { display: block; cursor: pointer; padding: 1rem 1.125rem; background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: var(--radius-md); transition: border-color var(--dur-fast) var(--ease-out-expo), background var(--dur-fast) var(--ease-out-expo); }
    .mode-card input { position: absolute; opacity: 0; pointer-events: none; }
    .mode-card:has(input:checked) { border-color: var(--amber); background: rgba(251,191,36,.06); box-shadow: 0 0 0 3px var(--amber-glow); }
    .mode-card__title { display: block; font-size: var(--text-sm); color: var(--text-primary); font-weight: 500; margin-bottom: .25rem; }
    .mode-card__desc { display: block; font-size: var(--text-xs); color: var(--text-tertiary); line-height: 1.5; }

    .toggle { display: flex; align-items: center; gap: .75rem; padding: 1rem 1.125rem; background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: var(--radius-md); cursor: pointer; }
    .toggle input { width: 1.1rem; height: 1.1rem; accent-color: var(--amber); }
    .toggle__title { font-size: var(--text-sm); color: var(--text-primary); }
    .toggle__desc { font-size: var(--text-xs); color: var(--text-tertiary); margin-top: .15rem; }

    .actions { display: flex; gap: .75rem; justify-content: flex-end; }
    .actions a, .actions button { padding: .65rem 1.25rem; border-radius: var(--radius-md); font: inherit; font-size: var(--text-sm); text-decoration: none; cursor: pointer; border: 0; }
    .actions a { background: transparent; color: var(--text-secondary); border: 1px solid var(--border-strong); }
    .actions button { background: var(--amber); color: #1a0e00; font-weight: 500; }
    .actions button:hover { background: var(--amber-soft); }

    .alert--err { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.08); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-size: var(--text-sm); }
</style>
@endpush

@section('content')
<section class="adv">
    <header class="adv__head">
        <span class="meta">/ advertise · campaign #{{ $ad->id }} · edit</span>
        <h1>Edit <em>campaign</em>.</h1>
        <p style="color: var(--text-secondary); margin: 0;">Title, description, display mode, daily limit, and pause/resume can change after launch. Target URL, reward, and total budget are locked once the campaign is created.</p>
    </header>

    @if ($errors->any())
        <div class="alert--err">
            @foreach ($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    <form class="form-card" method="POST" action="{{ route('advertise.update', ['id' => $ad->id]) }}" novalidate>
        @csrf
        @method('PATCH')

        <div class="field">
            <label for="title">Title</label>
            <input id="title" name="title" type="text" required maxlength="200"
                   value="{{ old('title', $ad->title) }}">
        </div>
        <div class="field">
            <label for="description">Description (optional)</label>
            <textarea id="description" name="description" maxlength="500">{{ old('description', $ad->description) }}</textarea>
        </div>

        <div class="field">
            <label>Target URL <span style="text-transform:none; font-style:italic; color: var(--text-tertiary);">(locked — re-submit a new campaign to change)</span></label>
            <div class="field__locked">{{ $ad->target_url }}</div>
        </div>

        @php $mode = old('display_mode', $ad->display_mode); @endphp
        <div class="field">
            <label>Display mode</label>
            <div class="mode-grid">
                <label class="mode-card">
                    <input type="radio" name="display_mode" value="window" @checked($mode === 'window')>
                    <span class="mode-card__title">New tab (recommended)</span>
                    <span class="mode-card__desc">Opens your URL in a separate tab on viewer click.</span>
                </label>
                <label class="mode-card">
                    <input type="radio" name="display_mode" value="iframe" @checked($mode === 'iframe')>
                    <span class="mode-card__title">Inline iframe</span>
                    <span class="mode-card__desc">Embeds the URL in the viewer page (only works if your site allows framing).</span>
                </label>
            </div>
        </div>

        <div class="field">
            <label for="daily_limit_per_user">Daily limit per viewer</label>
            <input id="daily_limit_per_user" name="daily_limit_per_user" type="number"
                   min="1" max="10" step="1" required
                   value="{{ old('daily_limit_per_user', $ad->daily_limit_per_user) }}">
            <span class="field__hint">How many times one viewer can claim per day. Lower = wider audience reach.</span>
        </div>

        <label class="toggle">
            {{-- The hidden input ensures the form posts is_active=0 when the
                 checkbox is off (browsers omit unchecked checkboxes entirely). --}}
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $ad->is_active))>
            <div>
                <div class="toggle__title">Active — currently {{ $ad->is_active ? 'serving viewers' : 'paused' }}</div>
                <div class="toggle__desc">Uncheck to pause delivery. Pausing keeps your approval — toggle back on anytime.</div>
            </div>
        </label>

        <div class="actions">
            <a href="{{ route('advertise.show', ['id' => $ad->id]) }}">Cancel</a>
            <button type="submit">Save changes</button>
        </div>
    </form>
</section>
@endsection
