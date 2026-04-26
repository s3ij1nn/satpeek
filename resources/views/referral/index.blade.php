@extends('layouts.app')

@php
    use App\Models\Referral;
    use App\Models\BalanceLedger;
    $u = auth()->user();
    $referralUrl = url('/register?ref=' . $u->referral_code);
    $pct = (int) config('satpeek.referral.commission_pct', 10);

    $referrals = $u->referrals()->orderByDesc('id')->limit(50)->get();
    $totalCommission = (int) BalanceLedger::where('user_id', $u->id)
        ->where('reason', 'referral_commission')
        ->sum('delta_sat');
    $invitedCount = $referrals->count();
@endphp

@push('head')
<style>
    .ref { max-width: 56rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 2rem; }
    .ref__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: .5rem 0 .25rem; }
    .ref__head h1 em { color: var(--amber-soft); font-style: italic; }
    .ref__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }

    .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px; background: var(--border-subtle); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); overflow: hidden; }
    @media (max-width: 540px) { .stats { grid-template-columns: 1fr; } }
    .stats__cell { background: var(--bg-panel); padding: 1.5rem 1.25rem; }
    .stats__num { font-family: var(--font-display); font-size: 2rem; line-height: 1; color: var(--amber-soft); letter-spacing: -.01em; }
    .stats__num small { font-family: var(--font-mono); font-size: .55em; color: var(--text-tertiary); margin-left: .25rem; }
    .stats__label { margin-top: .5rem; font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }

    .share-card { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem; display: grid; gap: 1rem; }
    .share-row { display: grid; grid-template-columns: 1fr auto; gap: .75rem; }
    .share-row input { background: var(--bg-canvas); border: 1px solid var(--border-strong); border-radius: var(--radius-md); padding: .65rem .85rem; color: var(--text-primary); font: inherit; font-family: var(--font-mono); font-size: var(--text-sm); }
    .share-row input:focus { outline: 0; border-color: var(--amber); }
    .copy-btn { padding: .65rem 1rem; border-radius: var(--radius-md); background: var(--amber); color: #1a0e00; font-weight: 500; font-size: var(--text-sm); border: 0; cursor: pointer; }
    .copy-btn:hover { background: var(--amber-soft); }
    .copy-btn.copied { background: var(--mint); }

    .panel { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.5rem; }
    .panel h2 { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0 0 1rem; font-weight: 500; }
    .ref-list { list-style: none; padding: 0; margin: 0; display: grid; gap: .25rem; }
    .ref-list li { display: grid; grid-template-columns: 1fr auto; gap: 1rem; padding: .55rem 0; border-top: 1px solid var(--border-faint); align-items: baseline; font-size: var(--text-sm); }
    .ref-list li:first-child { border-top: 0; }
    .ref-list .when { font-family: var(--font-mono); font-size: .65rem; color: var(--text-tertiary); }
    .ref-list .name { color: var(--text-secondary); }
</style>
@endpush

@section('content')
<section class="ref">
    <header class="ref__head">
        <span class="meta">/ referral</span>
        <h1>Bring <em>friends</em>.</h1>
        <p style="color: var(--text-secondary); margin: 0;">Share your link. You earn <strong style="color: var(--amber-soft);">{{ $pct }}%</strong> on every sat your invitees claim — for life.</p>
    </header>

    <div class="stats">
        <div class="stats__cell">
            <div class="stats__num">{{ $invitedCount }}<small>users</small></div>
            <div class="stats__label">Invited so far</div>
        </div>
        <div class="stats__cell">
            <div class="stats__num">{{ number_format($totalCommission) }}<small>sat</small></div>
            <div class="stats__label">Commission lifetime</div>
        </div>
        <div class="stats__cell">
            <div class="stats__num">{{ $pct }}<small>%</small></div>
            <div class="stats__label">Cut from each invitee</div>
        </div>
    </div>

    <div class="share-card">
        <h2 style="font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0; font-weight: 500;">Your invite link</h2>
        <div class="share-row">
            <input type="text" id="refUrl" value="{{ $referralUrl }}" readonly>
            <button type="button" class="copy-btn" id="copyUrl">Copy</button>
        </div>
        <h2 style="font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0; font-weight: 500;">Or just share your code</h2>
        <div class="share-row">
            <input type="text" id="refCode" value="{{ $u->referral_code }}" readonly>
            <button type="button" class="copy-btn" id="copyCode">Copy</button>
        </div>
    </div>

    <div class="panel">
        <h2>People you invited</h2>
        @if ($referrals->isEmpty())
            <p style="color: var(--text-tertiary); font-size: var(--text-sm);">No one yet — share the link above to start earning lifetime commission.</p>
        @else
            <ul class="ref-list">
                @foreach ($referrals as $invitee)
                    @php
                        $row = Referral::where('referrer_id', $u->id)->where('referred_id', $invitee->id)->first();
                        $earned = $row?->lifetime_commission_sat ?? 0;
                    @endphp
                    <li>
                        <span>
                            <span class="name">{{ $invitee->username }}</span><br>
                            <span class="when">joined {{ $invitee->created_at->diffForHumans() }}</span>
                        </span>
                        <span style="font-family: var(--font-mono); color: var(--mint);">+{{ number_format($earned) }} sat</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
@endsection

@push('body')
<script>
function attachCopy(buttonId, inputId) {
    const btn = document.getElementById(buttonId);
    const input = document.getElementById(inputId);
    if (!btn || !input) return;
    btn.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(input.value);
            const orig = btn.textContent;
            btn.textContent = '✓ Copied';
            btn.classList.add('copied');
            setTimeout(() => { btn.textContent = orig; btn.classList.remove('copied'); }, 1500);
        } catch (e) {
            input.select();
            document.execCommand('copy');
        }
    });
}
attachCopy('copyUrl', 'refUrl');
attachCopy('copyCode', 'refCode');
</script>
@endpush
