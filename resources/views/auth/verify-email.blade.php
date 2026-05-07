@extends('layouts.app')

@push('head')
<style>
    .auth { max-width: 32rem; margin: 0 auto; padding: clamp(3rem, 2rem + 5vw, 6rem) 1.5rem; text-align: center; }
    .auth__eyebrow { font-family: var(--font-mono); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: .18em; color: var(--text-tertiary); }
    .auth h1 { font-family: var(--font-display); font-size: var(--display-lg); line-height: 1.02; letter-spacing: -.02em; font-weight: 400; margin: 1rem 0; }
    .auth h1 em { font-style: italic; color: var(--amber-soft); }
    .auth p { color: var(--text-secondary); line-height: 1.6; margin: 0 0 1rem; }
    .verify__mark { width: 56px; height: 56px; border-radius: 50%; background: rgba(252,211,77,.12); border: 1px solid rgba(252,211,77,.5); display: inline-flex; align-items: center; justify-content: center; color: var(--amber-soft); font-size: 1.6rem; }
    .alert--ok { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(52,211,153,.08); border: 1px solid rgba(52,211,153,.3); color: var(--mint); font-size: var(--text-sm); margin: 1.5rem 0; }
    .alert--err { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.08); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-size: var(--text-sm); margin: 1.5rem 0; text-align: left; }
    .resend-form { display: block; margin-top: 1.5rem; }
    .resend-form .trajectory-captcha { margin: 0 auto 1rem; }
</style>
@endpush

@section('content')
<section class="auth">
    <span class="auth__eyebrow">/ one more step</span>
    <div class="verify__mark" aria-hidden="true">✉</div>
    <h1>Verify your <em>email</em>.</h1>
    <p>We sent a verification link to <strong style="color: var(--text-primary);">{{ auth()->user()?->email }}</strong>. Click it to unlock withdrawals.</p>
    <p style="font-size: var(--text-sm); color: var(--text-tertiary);">PTC viewing and shortlinks are available right now — verification is only required when you withdraw to FaucetPay.</p>

    @if (session('status')) <div class="alert--ok" role="status">{{ session('status') }}</div> @endif
    @error('captcha') <div class="alert--err" role="alert">{{ $message }}</div> @enderror
    <div id="resendError" class="alert--err" role="alert" aria-live="assertive" style="display:none"></div>

    <form id="resendForm" method="POST" action="{{ route('verification.send') }}" class="resend-form">
        @csrf
        <x-trajectory-captcha />
        <button type="submit" class="cta cta--ghost" id="resendSubmit">↻ Re-send verification email</button>
    </form>
    <p style="margin-top: 2rem; font-size: var(--text-xs);">
        <a href="{{ route('dashboard') }}" style="color: var(--text-tertiary); text-decoration: underline;">Continue to dashboard</a>
        &nbsp;·&nbsp;
        {{-- Logout is a state-changing action and CSRF-protected, so it MUST
             be a POST form. Previously rendered as <a> with a JS form-submit
             onclick — keyboard-operable but the GET href violated CSRF-safe-
             method semantics if JS failed. The styled <button> below is
             POST-only, keyboard-native, and SR-correct. --}}
        <form method="POST" action="{{ route('logout') }}"
              style="display:inline; margin:0; padding:0;">
            @csrf
            <button type="submit" class="logout-link"
                    style="background:none; border:0; padding:0; font:inherit;
                           color: var(--text-tertiary); text-decoration: underline; cursor:pointer;">Sign out</button>
        </form>
    </p>
</section>
@endsection

@push('body')
<script>
(() => {
    const form = document.getElementById('resendForm');
    const errorBox = document.getElementById('resendError');
    const submitBtn = document.getElementById('resendSubmit');

    function showError(msg) { errorBox.textContent = msg; errorBox.style.display = 'block'; }
    function clearError() { errorBox.style.display = 'none'; errorBox.textContent = ''; }

    form.addEventListener('submit', (e) => {
        clearError();
        const cap = form.querySelector('[data-trajectory-captcha]');
        const state = cap?.spGetState ? cap.spGetState() : { isReady: false };
        if (!state.isReady) {
            e.preventDefault();
            showError('Please solve the captcha — drag the token along the path before submitting.');
            return;
        }
        // Native form submit — server returns a redirect with flash status / errors.
        // The captcha refreshes itself on the next page render via the blade
        // component; no manual reset needed because back() reloads the form.
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'Sending…';
    });
})();
</script>
@endpush
