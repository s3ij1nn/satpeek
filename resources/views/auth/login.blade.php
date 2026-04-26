@extends('layouts.app')

@push('head')
<style>
    .auth { max-width: 30rem; margin: 0 auto; padding: clamp(3rem, 2rem + 5vw, 6rem) 1.5rem; }
    .auth__eyebrow { font-family: var(--font-mono); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .18em; color: var(--text-tertiary); }
    .auth h1 { font-family: var(--font-display); font-size: var(--display-lg); line-height: 1.02; letter-spacing: -.02em; font-weight: 400; margin: .75rem 0 1rem; }
    .auth h1 em { font-style: italic; color: var(--amber-soft); }
    .auth p.lede { color: var(--text-secondary); margin: 0 0 2rem; line-height: 1.55; }
    .form-card { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 2rem; display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
    .form-card > * { min-width: 0; }
    .field { display: grid; gap: .4rem; }
    .field label { font-family: var(--font-mono); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .14em; color: var(--text-tertiary); }
    .field input { background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: .75rem .875rem; color: var(--text-primary); font: inherit; }
    .field input:focus { outline: 0; border-color: var(--amber); box-shadow: 0 0 0 3px var(--amber-glow); }
    .field__error { font-size: var(--text-xs); color: var(--rose); }
    .between { display: flex; justify-content: space-between; align-items: center; font-size: var(--text-sm); color: var(--text-tertiary); }
    .between a { color: var(--amber-soft); text-decoration: underline; }
    .alert--err { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.08); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-size: var(--text-sm); margin-bottom: 1.25rem; }
    .alert--ok { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(52,211,153,.08); border: 1px solid rgba(52,211,153,.3); color: var(--mint); font-size: var(--text-sm); margin-bottom: 1.25rem; }
    .alt-link { text-align: center; margin: 1.5rem 0 0; font-size: var(--text-sm); color: var(--text-tertiary); }
    .alt-link a { color: var(--amber-soft); text-decoration: underline; }
</style>
@endpush

@section('content')
<section class="auth">
    <span class="auth__eyebrow">/ sign in</span>
    <h1>Welcome <em>back</em>.</h1>
    <p class="lede">Pick up where you left off. Solve the trajectory captcha to confirm you're human.</p>

    @if (session('status')) <div class="alert--ok">{{ session('status') }}</div> @endif

    <div id="loginError" class="alert--err" role="alert" style="display:none;"></div>
    @error('email') <div class="alert--err">{{ $message }}</div> @enderror

    <form class="form-card" method="POST" action="{{ route('login.store') }}" id="loginForm" novalidate>
        @csrf
        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required autocomplete="email"
                   placeholder="you@inbox.com" value="{{ old('email') }}">
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>

        <x-trajectory-captcha />

        <div class="between">
            <label style="display:flex;align-items:center;gap:.4rem;">
                <input type="checkbox" name="remember" value="1" style="accent-color: var(--amber);">
                <span>Remember me</span>
            </label>
            <a href="{{ route('password.request') }}">Forgot password?</a>
        </div>

        <button type="submit" class="cta cta--primary" id="loginSubmit"
                style="margin-top:.5rem; justify-content:center;">
            Sign in <span class="cta__arrow">→</span>
        </button>
    </form>

    <p class="alt-link">Don't have an account? <a href="{{ route('register') }}">Sign up free →</a></p>
</section>
@endsection

@push('body')
<script>
(() => {
    const form = document.getElementById('loginForm');
    const errorBox = document.getElementById('loginError');
    const submitBtn = document.getElementById('loginSubmit');
    function showError(msg) { errorBox.textContent = msg; errorBox.style.display = 'block'; }
    function clearError() { errorBox.style.display = 'none'; errorBox.textContent = ''; }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearError();
        const cap = form.querySelector('[data-trajectory-captcha]');
        const state = cap?.spGetState ? cap.spGetState() : { isReady: false };
        if (!state.isReady) { showError('Please solve the captcha first.'); return; }
        const original = submitBtn.innerHTML;
        submitBtn.disabled = true; submitBtn.innerHTML = 'Signing in…';
        try {
            const r = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-SP-Fingerprint': window.SPCaptcha?.fingerprint || '',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });
            let data = null;
            try { data = await r.json(); } catch {}
            if (r.ok && data?.status === 'ok') {
                location.href = data.redirect || '/dashboard';
                return;
            }
            showError(data?.message || `Sign in failed (HTTP ${r.status}).`);
            const block = form.querySelector('[data-trajectory-captcha]');
            if (block && block.spReset) await block.spReset();
        } catch (err) {
            showError('Network error — please try again.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = original;
        }
    });
})();
</script>
@endpush
