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
    .resend-form { display: inline; }
</style>
@endpush

@section('content')
<section class="auth">
    <span class="auth__eyebrow">/ one more step</span>
    <div class="verify__mark" aria-hidden="true">✉</div>
    <h1>Verify your <em>email</em>.</h1>
    <p>We sent a verification link to <strong style="color: var(--text-primary);">{{ auth()->user()?->email }}</strong>. Click it to unlock withdrawals.</p>
    <p style="font-size: var(--text-sm); color: var(--text-tertiary);">PTC viewing and shortlinks are available right now — verification is only required when you withdraw to FaucetPay.</p>

    @if (session('status')) <div class="alert--ok">{{ session('status') }}</div> @endif

    <form method="POST" action="{{ route('verification.send') }}" class="resend-form">
        @csrf
        <button type="submit" class="cta cta--ghost" style="margin-top: 1.5rem;">↻ Re-send verification email</button>
    </form>
    <p style="margin-top: 2rem; font-size: var(--text-xs);">
        <a href="{{ route('dashboard') }}" style="color: var(--text-tertiary); text-decoration: underline;">Continue to dashboard</a>
        &nbsp;·&nbsp;
        <a href="{{ route('logout') }}" style="color: var(--text-tertiary); text-decoration: underline;"
           onclick="event.preventDefault(); document.getElementById('lo').submit();">Sign out</a>
        <form id="lo" method="POST" action="{{ route('logout') }}" style="display:none;">@csrf</form>
    </p>
</section>
@endsection
