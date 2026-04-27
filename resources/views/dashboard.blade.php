@extends('layouts.app')

@php
    use App\Models\BalanceLedger;
    use App\Models\PtcView;
    use App\Models\Withdrawal;
    use Illuminate\Support\Carbon;
    $u = auth()->user();
    $todayStart = Carbon::now()->startOfDay();
    $earnedToday = (int) BalanceLedger::where('user_id', $u->id)
        ->where('created_at', '>=', $todayStart)
        ->where('delta_sat', '>', 0)
        ->sum('delta_sat');
    $viewsToday = (int) PtcView::where('user_id', $u->id)
        ->where('status', 'verified')
        ->where('created_at', '>=', $todayStart)
        ->count();
    $pendingWithdrawals = Withdrawal::where('user_id', $u->id)
        ->whereIn('status', ['queued', 'hold', 'processing'])
        ->orderByDesc('id')
        ->limit(5)
        ->get();
    $recentLedger = BalanceLedger::where('user_id', $u->id)
        ->orderByDesc('id')
        ->limit(10)
        ->get();
    $tier = $u->botScore?->tier ?? 'trust';
    $verified = $u->hasVerifiedEmail();
@endphp

@push('head')
<style>
    .dash { max-width: 64rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 2rem; }
    .dash__head { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 1rem; }
    .dash__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: 0; }
    .dash__head h1 em { color: var(--amber-soft); font-style: italic; }
    .dash__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }
    .alert--ok { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(52,211,153,.08); border: 1px solid rgba(52,211,153,.3); color: var(--mint); font-size: var(--text-sm); }
    .alert--warn { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(252,211,77,.08); border: 1px solid rgba(252,211,77,.3); color: var(--amber-soft); font-size: var(--text-sm); }
    .alert--warn a { color: var(--amber-soft); text-decoration: underline; }

    .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: var(--border-subtle); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); overflow: hidden; }
    @media (max-width: 720px) { .stats { grid-template-columns: repeat(2, 1fr); } }
    .stats__cell { background: var(--bg-panel); padding: 1.5rem 1.25rem; }
    .stats__num { font-family: var(--font-display); font-size: 2rem; line-height: 1; color: var(--amber-soft); letter-spacing: -.01em; }
    .stats__num small { font-family: var(--font-mono); font-size: .55em; color: var(--text-tertiary); margin-left: .25rem; }
    .stats__label { margin-top: .5rem; font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }
    .tier-badge { display: inline-block; padding: .12rem .5rem; border-radius: 4px; font-family: var(--font-mono); font-size: .7em; text-transform: uppercase; }
    .tier-trust { background: rgba(52,211,153,.12); color: var(--mint); }
    .tier-suspect { background: rgba(252,211,77,.12); color: var(--amber-soft); }
    .tier-likely_bot, .tier-banned { background: rgba(251,113,133,.12); color: var(--rose); }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
    @media (max-width: 720px) { .grid-2 { grid-template-columns: 1fr; } }
    .panel { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem; }
    .panel h2 { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0 0 1rem; font-weight: 500; }

    .ledger { list-style: none; padding: 0; margin: 0; display: grid; gap: .25rem; }
    .ledger li { display: grid; grid-template-columns: 1fr auto; gap: 1rem; padding: .55rem 0; border-top: 1px solid var(--border-faint); align-items: baseline; font-size: var(--text-sm); }
    .ledger li:first-child { border-top: 0; }
    .ledger .when { font-family: var(--font-mono); font-size: .65rem; color: var(--text-tertiary); }
    .ledger .reason { color: var(--text-secondary); }
    .ledger .delta { font-family: var(--font-mono); font-size: var(--text-sm); white-space: nowrap; }
    .ledger .delta.pos { color: var(--mint); }
    .ledger .delta.neg { color: var(--rose); }

    .quick-cta { display: flex; flex-wrap: wrap; gap: .75rem; }
    .quick-cta .cta { font-size: var(--text-sm); }
</style>
@endpush

@section('content')
<section class="dash">
    @if (session('status')) <div class="alert--ok">{{ session('status') }}</div> @endif

    @unless ($verified)
        <div class="alert--warn">
            ⚠ Your email isn't verified yet. PTC and shortlinks are open, but withdrawals stay locked until you click the link we sent.
            <a href="{{ route('verification.notice') }}">Resend verification →</a>
        </div>
    @endunless

    <header class="dash__head">
        <div>
            <span class="meta">/ dashboard</span>
            <h1>Hey, <em>{{ $u->username }}</em>.</h1>
        </div>
        <div class="meta">
            tier: <span class="tier-badge tier-{{ $tier }}">{{ $tier }}</span>
        </div>
    </header>

    {{-- Anti-adblock banner — orthogonal to tier so it ALWAYS shows
         when detected, even for trust-tier users. Earning APIs are
         already gated server-side by AdblockGate; this banner is the
         user-facing explanation of why the buttons aren't working. --}}
    @if (($u->adblock_status ?? null) === 'detected')
        <div class="alert--err" style="padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.12); border: 1px solid rgba(251,113,133,.45); color: var(--rose); font-size: var(--text-sm);">
            <strong>Adblock or Brave shields detected.</strong> SatPeek's economics depend on ad impressions — earning surfaces (PTC / shortlinks / withdrawals) are paused for your account until ads are allowed.
            <ul style="margin: .5rem 0 0; padding-left: 1.25rem;">
                <li>uBlock / AdBlock Plus: click the extension icon → "Don't run on this site".</li>
                <li>Brave: click the lion icon in the address bar → set <strong>Shields</strong> to Down for this site.</li>
            </ul>
            Reload the page after disabling — the check re-runs automatically.
        </div>
    @endif

    {{-- Tier-driven status banner. Hidden on the trust path so the
         dashboard stays clean for the ~95 % of users who never see one
         of these. The text explains the *current* posture in plain
         language so a legit user hit by a shared-NAT false positive
         can self-diagnose and so a real bot operator gets a clear
         "you've been caught" signal. --}}
    @if ($tier === 'suspect')
        <div class="alert--warn">
            <strong>Heads up:</strong> activity on your account looks unusual — you'll see a slightly stricter captcha and withdrawals may be held briefly for a review pass. Most often this resolves on its own as your activity history builds. If you're on shared wifi (campus / mobile / corporate), this is a known false-positive surface; reach out to support if it persists.
        </div>
    @elseif ($tier === 'likely_bot')
        <div class="alert--err" style="padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.08); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-size: var(--text-sm);">
            <strong>PTC paused.</strong> Recent activity matched several bot-detection signals at once (cookie-clear / multi-IP / captcha-timing patterns). PTC viewing and withdrawals are temporarily on hold while the system reassesses. The captcha is also harder — completing several legitimately will rebuild trust. Email <a href="mailto:{{ config('mail.from.address') }}" style="color: var(--rose); text-decoration: underline;">support</a> if you believe this is wrong.
        </div>
    @elseif ($tier === 'banned' || $u->is_banned)
        <div class="alert--err" style="padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.12); border: 1px solid rgba(251,113,133,.45); color: var(--rose); font-size: var(--text-sm);">
            <strong>Account suspended.</strong> Earning surfaces are disabled. @if ($u->ban_reason) Reason: <code>{{ $u->ban_reason }}</code>. @endif If this is wrong, email <a href="mailto:{{ config('mail.from.address') }}" style="color: var(--rose); text-decoration: underline;">support</a>.
        </div>
    @endif

    <div class="stats">
        <div class="stats__cell">
            <div class="stats__num">{{ number_format($u->balance_sat) }}<small>sat</small></div>
            <div class="stats__label">Balance</div>
        </div>
        <div class="stats__cell">
            <div class="stats__num">{{ number_format($earnedToday) }}<small>sat</small></div>
            <div class="stats__label">Earned today</div>
        </div>
        <div class="stats__cell">
            <div class="stats__num">{{ number_format($viewsToday) }}<small>views</small></div>
            <div class="stats__label">PTC views today</div>
        </div>
        <div class="stats__cell">
            <div class="stats__num">{{ number_format((int) $u->total_withdrawn_sat) }}<small>sat</small></div>
            <div class="stats__label">Total withdrawn</div>
        </div>
    </div>

    <div class="quick-cta">
        <a href="{{ route('ptc.index') }}" class="cta cta--primary">View PTC ads <span class="cta__arrow">→</span></a>
        <a href="{{ route('shortlinks.index') }}" class="cta cta--ghost">Browse shortlinks</a>
        @if ($u->balance_sat >= (int) config('satpeek.faucetpay.min_withdraw_sat', 1000) && $verified)
            <a href="{{ route('withdraw.index') }}" class="cta cta--ghost">Request payout</a>
        @endif
    </div>

    <div class="grid-2">
        <div class="panel">
            <h2>Recent activity</h2>
            @if ($recentLedger->isEmpty())
                <p style="color: var(--text-tertiary); font-size: var(--text-sm);">No earnings yet. <a href="{{ route('ptc.index') }}" style="color: var(--amber-soft); text-decoration: underline;">View an ad to get started.</a></p>
            @else
                <ul class="ledger">
                    @foreach ($recentLedger as $row)
                        <li>
                            <span>
                                <span class="reason">{{ str_replace('_', ' ', $row->reason) }}</span><br>
                                <span class="when">{{ $row->created_at->diffForHumans() }}</span>
                            </span>
                            <span class="delta {{ $row->delta_sat >= 0 ? 'pos' : 'neg' }}">
                                {{ $row->delta_sat >= 0 ? '+' : '' }}{{ number_format($row->delta_sat) }} sat
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="panel">
            <h2>Pending withdrawals</h2>
            @if ($pendingWithdrawals->isEmpty())
                <p style="color: var(--text-tertiary); font-size: var(--text-sm);">No payouts in flight.</p>
            @else
                <ul class="ledger">
                    @foreach ($pendingWithdrawals as $w)
                        <li>
                            <span>
                                <span class="reason">{{ number_format($w->amount_sat) }} {{ $w->currency }} → {{ $w->faucetpay_email }}</span><br>
                                <span class="when">{{ $w->created_at->diffForHumans() }} · status: <span class="tier-badge tier-{{ $w->status === 'sent' ? 'trust' : ($w->status === 'hold' ? 'suspect' : 'trust') }}">{{ $w->status }}</span></span>
                            </span>
                            <span class="delta">—</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</section>
@endsection
