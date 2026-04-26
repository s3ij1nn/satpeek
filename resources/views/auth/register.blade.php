@extends('layouts.app')

@push('head')
<style>
    .auth { max-width: 36rem; margin: 0 auto; padding: clamp(3rem, 2rem + 5vw, 6rem) 1.5rem; }
    .auth__eyebrow { font-family: var(--font-mono); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .18em; color: var(--text-tertiary); }
    .auth h1 { font-family: var(--font-display); font-size: var(--display-lg); line-height: 1.02; letter-spacing: -.02em; font-weight: 400; margin: .75rem 0 1rem; }
    .auth h1 em { font-style: italic; color: var(--amber-soft); }
    .auth p.lede { color: var(--text-secondary); font-size: var(--text-lg); margin: 0 0 2rem; line-height: 1.6; }
    .form-card { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 2rem; display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
    .form-card > * { min-width: 0; }
    .field { display: grid; gap: .4rem; }
    .field label { font-family: var(--font-mono); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .14em; color: var(--text-tertiary); }
    .field input { background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: .75rem .875rem; color: var(--text-primary); font: inherit; transition: border-color var(--dur-fast) var(--ease-out-expo); }
    .field input:focus { outline: 0; border-color: var(--amber); box-shadow: 0 0 0 3px var(--amber-glow); }
    .field__hint { font-size: var(--text-xs); color: var(--text-tertiary); }
    .field__error { font-size: var(--text-xs); color: var(--rose); }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    @media (max-width: 540px) { .field-row { grid-template-columns: 1fr; } }
    .auth-tos { display: flex; align-items: flex-start; gap: .6rem; font-size: var(--text-sm); color: var(--text-secondary); }
    .auth-tos input[type=checkbox] { margin-top: .25rem; accent-color: var(--amber); }
    .alert--err { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.08); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-size: var(--text-sm); margin-bottom: 1.25rem; }
    .alt-link { text-align: center; margin: 1.5rem 0 0; font-size: var(--text-sm); color: var(--text-tertiary); }
    .alt-link a { color: var(--amber-soft); text-decoration: underline; }
    .honeypot { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
</style>
@endpush

@section('content')
<section class="auth">
    <span class="auth__eyebrow">/ create your free account</span>
    <h1>Sign up &amp; <em>start earning</em>.</h1>
    <p class="lede">Username, email, password — that's it. We'll verify the email to unlock withdrawals to FaucetPay.</p>

    <div id="signupError" class="alert--err" role="alert" style="display:none;"></div>
    @error('captcha') <div class="alert--err">{{ $message }}</div> @enderror

    <form class="form-card" method="POST" action="{{ route('register.store') }}" id="signupForm" novalidate>
        @csrf

        <div class="field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" required minlength="3" maxlength="32"
                   pattern="[A-Za-z0-9_]+" autocomplete="username"
                   placeholder="lowercase, digits, underscore" value="{{ old('username') }}">
            @error('username') <span class="field__error">{{ $message }}</span> @enderror
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required autocomplete="email"
                   placeholder="you@inbox.com" value="{{ old('email') }}">
            @error('email') <span class="field__error">{{ $message }}</span> @enderror
        </div>

        <div class="field-row">
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
                @error('password') <span class="field__error">{{ $message }}</span> @enderror
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password">
            </div>
        </div>

        <div class="field">
            <label for="faucetpay_email">FaucetPay address (optional)</label>
            <input id="faucetpay_email" name="faucetpay_email" type="email" autocomplete="off"
                   placeholder="leave blank to add later" value="{{ old('faucetpay_email') }}">
            <span class="field__hint">Used as the default destination for withdrawals — change it anytime in your account.</span>
        </div>

        <div class="field">
            <label for="referral_code">Referral code (optional)</label>
            <input id="referral_code" name="referral_code" type="text" maxlength="16"
                   placeholder="from a friend"
                   value="{{ old('referral_code', $referralCode ?? request()->query('ref')) }}">
        </div>

        <x-trajectory-captcha />

        <label class="auth-tos">
            <input type="checkbox" name="agree" value="1" required>
            <span>I am 18+ and agree no faucet-farming, multi-accounting, or relayed traffic.</span>
        </label>

        <div class="honeypot" aria-hidden="true">
            <label>Website (leave empty)<input type="text" name="website" tabindex="-1"></label>
        </div>

        <button type="submit" class="cta cta--primary" id="signupSubmit"
                style="margin-top:.5rem; justify-content:center;">
            Create free account <span class="cta__arrow">→</span>
        </button>
    </form>

    <p class="alt-link">Already have an account? <a href="{{ route('login') }}">Sign in →</a></p>
</section>
@endsection

@push('body')
<script>
(() => {
    const form = document.getElementById('signupForm');
    const errorBox = document.getElementById('signupError');
    const submitBtn = document.getElementById('signupSubmit');

    function showError(msg) { errorBox.textContent = msg; errorBox.style.display = 'block'; errorBox.scrollIntoView({behavior:'smooth', block:'nearest'}); }
    function clearError() { errorBox.style.display = 'none'; errorBox.textContent = ''; }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearError();
        const cap = form.querySelector('[data-trajectory-captcha]');
        const state = cap?.spGetState ? cap.spGetState() : { isReady: false };
        if (!state.isReady) {
            showError('Please solve the captcha — drag the token along the path before submitting.');
            return;
        }
        const original = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'Creating…';
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
            const msg = data?.message
                || (data?.errors ? Object.values(data.errors).flat().join(' ') : '')
                || `Sign-up failed (HTTP ${r.status}).`;
            showError(msg);
            // Refresh the captcha so the next attempt has a fresh challenge.
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
