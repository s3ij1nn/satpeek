@extends('layouts.app')

@push('head')
<style>
    .auth { max-width: 30rem; margin: 0 auto; padding: clamp(3rem, 2rem + 5vw, 6rem) 1.5rem; }
    .auth__eyebrow { font-family: var(--font-mono); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .18em; color: var(--text-tertiary); }
    .auth h1 { font-family: var(--font-display); font-size: var(--display-lg); line-height: 1.02; letter-spacing: -.02em; font-weight: 400; margin: .75rem 0 1rem; }
    .auth h1 em { font-style: italic; color: var(--amber-soft); }
    .form-card { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 2rem; display: grid; gap: 1rem; margin-top: 2rem; }
    .field { display: grid; gap: .4rem; }
    .field label { font-family: var(--font-mono); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .14em; color: var(--text-tertiary); }
    .field input { background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: .75rem .875rem; color: var(--text-primary); font: inherit; }
    .field input:focus { outline: 0; border-color: var(--amber); box-shadow: 0 0 0 3px var(--amber-glow); }
    .field__error { font-size: var(--text-xs); color: var(--rose); }
</style>
@endpush

@section('content')
<section class="auth">
    <span class="auth__eyebrow">/ choose new password</span>
    <h1>Set a <em>new password</em>.</h1>

    <form class="form-card" method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required value="{{ old('email', $email) }}">
            @error('email') <span class="field__error">{{ $message }}</span> @enderror
        </div>
        <div class="field">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
            @error('password') <span class="field__error">{{ $message }}</span> @enderror
        </div>
        <div class="field">
            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="cta cta--primary" style="justify-content:center;">
            Update password <span class="cta__arrow">→</span>
        </button>
    </form>
</section>
@endsection
