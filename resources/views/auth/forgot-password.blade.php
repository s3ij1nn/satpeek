@extends('layouts.app')

@push('head')
<style>
    .auth { max-width: 30rem; margin: 0 auto; padding: clamp(3rem, 2rem + 5vw, 6rem) 1.5rem; }
    .auth__eyebrow { font-family: var(--font-mono); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .18em; color: var(--text-tertiary); }
    .auth h1 { font-family: var(--font-display); font-size: var(--display-lg); line-height: 1.02; letter-spacing: -.02em; font-weight: 400; margin: .75rem 0 1rem; }
    .auth h1 em { font-style: italic; color: var(--amber-soft); }
    .auth p.lede { color: var(--text-secondary); margin: 0 0 2rem; line-height: 1.55; }
    .form-card { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 2rem; display: grid; gap: 1rem; }
    .field { display: grid; gap: .4rem; }
    .field label { font-family: var(--font-mono); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .14em; color: var(--text-tertiary); }
    .field input { background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: .75rem .875rem; color: var(--text-primary); font: inherit; }
    .field input:focus { outline: 0; border-color: var(--amber); box-shadow: 0 0 0 3px var(--amber-glow); }
    .alert--ok { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(52,211,153,.08); border: 1px solid rgba(52,211,153,.3); color: var(--mint); font-size: var(--text-sm); margin-bottom: 1.25rem; }
    .alt-link { text-align: center; margin: 1.5rem 0 0; font-size: var(--text-sm); color: var(--text-tertiary); }
    .alt-link a { color: var(--amber-soft); text-decoration: underline; }
</style>
@endpush

@section('content')
<section class="auth">
    <span class="auth__eyebrow">/ password reset</span>
    <h1>Forgot it? <em>No problem.</em></h1>
    <p class="lede">Drop the email you signed up with and we'll send a one-time reset link.</p>

    @if (session('status')) <div class="alert--ok">{{ session('status') }}</div> @endif

    <form class="form-card" method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required autocomplete="email"
                   placeholder="you@inbox.com" value="{{ old('email') }}">
        </div>
        <button type="submit" class="cta cta--primary" style="justify-content:center;">
            Email me a reset link <span class="cta__arrow">→</span>
        </button>
    </form>

    <p class="alt-link"><a href="{{ route('login') }}">← Back to sign in</a></p>
</section>
@endsection
