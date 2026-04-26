@extends('layouts.app')

@push('head')
<style>
    /* — HERO — */
    .hero {
        padding: clamp(3rem, 2rem + 6vw, 7rem) 1.5rem clamp(2.5rem, 1rem + 5vw, 5rem);
        position: relative;
        overflow: hidden;
    }
    .hero::before {
        content: '';
        position: absolute; inset: 0;
        background:
            radial-gradient(60rem 30rem at 80% -10%, rgba(245, 158, 11, 0.08), transparent 60%),
            radial-gradient(40rem 20rem at -10% 30%, rgba(103, 232, 249, 0.05), transparent 60%);
        pointer-events: none;
        z-index: 0;
    }
    .hero__inner {
        max-width: 76rem; margin: 0 auto;
        position: relative; z-index: 1;
        display: grid; gap: clamp(2rem, 1rem + 3vw, 4rem);
        grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
        align-items: center;
    }
    @media (max-width: 880px) {
        .hero__inner { grid-template-columns: 1fr; }
    }

    .eyebrow {
        display: inline-flex; align-items: center; gap: 0.5rem;
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        text-transform: uppercase;
        letter-spacing: 0.18em;
        color: var(--text-tertiary);
        padding: 0.375rem 0.75rem;
        border: 1px solid var(--border-subtle);
        border-radius: 999px;
        background: var(--bg-elev);
    }
    .eyebrow__pulse {
        width: 6px; height: 6px; border-radius: 50%;
        background: var(--mint);
        box-shadow: 0 0 0 0 rgba(52, 211, 153, 0.6);
        animation: pulse 2s var(--ease-out-expo) infinite;
    }
    @keyframes pulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0.6); }
        50% { box-shadow: 0 0 0 8px rgba(52, 211, 153, 0); }
    }

    .display {
        font-family: var(--font-display);
        font-size: var(--display-xl);
        line-height: 0.96;
        letter-spacing: -0.025em;
        font-weight: 400;
        margin: 1.25rem 0 0;
        color: var(--text-primary);
    }
    .display em {
        font-style: italic;
        color: var(--amber-soft);
    }
    .display .struck {
        position: relative;
        color: var(--text-quaternary);
    }
    .display .struck::after {
        content: '';
        position: absolute;
        left: -2%; right: -2%;
        top: 52%;
        height: 0.08em;
        background: var(--rose);
        transform-origin: left;
        animation: strike 1.4s var(--ease-out-expo) 0.6s both;
    }
    @keyframes strike {
        from { transform: scaleX(0); }
        to { transform: scaleX(1); }
    }

    .lede {
        max-width: 34rem;
        font-size: var(--text-lg);
        color: var(--text-secondary);
        margin: 1.5rem 0 0;
        line-height: 1.6;
    }

    .cta-row {
        margin-top: 2.25rem;
        display: flex; flex-wrap: wrap; gap: 0.875rem;
        align-items: center;
    }
    .cta {
        display: inline-flex; align-items: center; gap: 0.5rem;
        padding: 0.75rem 1.25rem;
        border-radius: var(--radius-md);
        font-size: var(--text-sm);
        font-weight: 500;
        cursor: pointer;
        transition: all var(--dur-fast) var(--ease-out-expo);
        border: 1px solid transparent;
    }
    .cta--primary {
        background: var(--amber);
        color: #1a0e00;
        box-shadow: 0 1px 0 rgba(255,255,255,0.2) inset, 0 8px 24px var(--amber-glow);
    }
    .cta--primary:hover {
        background: var(--amber-soft);
        transform: translateY(-1px);
        box-shadow: 0 1px 0 rgba(255,255,255,0.3) inset, 0 12px 32px rgba(245, 158, 11, 0.32);
    }
    .cta--ghost {
        color: var(--text-secondary);
        border-color: var(--border-strong);
    }
    .cta--ghost:hover {
        color: var(--text-primary);
        border-color: var(--text-secondary);
    }
    .cta__arrow { transition: transform var(--dur-fast) var(--ease-out-expo); }
    .cta:hover .cta__arrow { transform: translateX(3px); }

    .micro-meta {
        display: flex; gap: 1.25rem; flex-wrap: wrap;
        margin-top: 2.25rem;
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        color: var(--text-tertiary);
    }
    .micro-meta span { display: inline-flex; align-items: center; gap: 0.4rem; }
    .micro-meta .dot { width: 4px; height: 4px; border-radius: 50%; background: var(--mint); }

    /* — Captcha Demo Card — */
    .demo {
        position: relative;
        background: linear-gradient(145deg, var(--bg-elev) 0%, var(--bg-panel) 100%);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        padding: 1rem;
        box-shadow:
            0 1px 0 rgba(255,255,255,0.04) inset,
            0 30px 60px -20px rgba(0, 0, 0, 0.6),
            0 0 0 1px rgba(0,0,0,0.2);
    }
    .demo__chrome {
        display: flex; align-items: center; gap: 0.5rem;
        padding: 0.25rem 0.5rem 0.75rem;
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        color: var(--text-tertiary);
        border-bottom: 1px solid var(--border-faint);
        margin-bottom: 0.75rem;
    }
    .demo__dots { display: inline-flex; gap: 0.3rem; margin-right: 0.5rem; }
    .demo__dots span {
        width: 8px; height: 8px; border-radius: 50%;
        background: var(--border-strong);
    }
    .demo__title { letter-spacing: 0.05em; text-transform: uppercase; }
    .demo__status {
        margin-left: auto;
        color: var(--mint);
        font-size: 0.625rem;
    }
    .demo canvas {
        display: block;
        width: 100%; height: auto; max-width: 100%;
        aspect-ratio: 320 / 240;
        border-radius: var(--radius-md);
        background: #060912;
        cursor: crosshair;
        touch-action: none;
    }
    .demo__instr {
        display: flex; justify-content: space-between; align-items: center;
        margin-top: 0.75rem;
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        color: var(--text-tertiary);
    }
    .demo__readout {
        display: flex; gap: 0.875rem;
    }
    .demo__readout b {
        color: var(--text-secondary);
        font-weight: 600;
    }
    .demo__verdict {
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        padding: 0.2rem 0.5rem;
        border-radius: var(--radius-sm);
        background: var(--bg-elev-2);
        color: var(--text-tertiary);
        transition: all var(--dur-normal) var(--ease-out-expo);
    }
    .demo__verdict[data-state="pass"] {
        background: rgba(52, 211, 153, 0.12);
        color: var(--mint);
    }
    .demo__verdict[data-state="fail"] {
        background: rgba(251, 113, 133, 0.12);
        color: var(--rose);
    }

    /* — Section frame — */
    section.section {
        padding: var(--space-section) 0;
        scroll-margin-top: 4rem;
    }
    .section__head {
        max-width: 76rem; margin: 0 auto;
        padding: 0 1.5rem 2.5rem;
        display: grid; gap: 1rem;
        grid-template-columns: minmax(0, 0.7fr) minmax(0, 1fr);
        align-items: end;
    }
    @media (max-width: 760px) {
        .section__head { grid-template-columns: 1fr; }
    }
    .section__head h2 {
        font-family: var(--font-display);
        font-size: var(--display-md);
        line-height: 1.05;
        letter-spacing: -0.02em;
        font-weight: 400;
        margin: 0;
    }
    .section__head h2 em {
        font-style: italic;
        color: var(--amber-soft);
    }
    .section__head p {
        color: var(--text-secondary);
        max-width: 40ch;
        margin: 0;
    }
    .section__index {
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        color: var(--text-tertiary);
        text-transform: uppercase;
        letter-spacing: 0.18em;
        margin-bottom: 0.75rem;
        display: block;
    }

    /* — Bento — */
    .bento {
        max-width: 76rem; margin: 0 auto;
        padding: 0 1.5rem;
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }
    .bento__card {
        background: var(--bg-panel);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        padding: 1.75rem;
        position: relative; overflow: hidden;
        transition: border-color var(--dur-normal) var(--ease-out-expo),
                    transform var(--dur-normal) var(--ease-out-expo);
    }
    .bento__card:hover { border-color: var(--border-strong); transform: translateY(-2px); }
    .bento__card--earn { grid-column: span 3; }
    .bento__card--defend { grid-column: span 3; }
    .bento__card--cash { grid-column: span 6; padding-bottom: 1.25rem; }
    @media (max-width: 760px) {
        .bento__card--earn, .bento__card--defend, .bento__card--cash { grid-column: 1 / -1; }
    }
    .bento__num {
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        color: var(--text-tertiary);
        letter-spacing: 0.18em;
        text-transform: uppercase;
    }
    .bento__title {
        font-family: var(--font-display);
        font-size: var(--display-md);
        line-height: 1; letter-spacing: -0.02em;
        margin: 0.75rem 0 0.5rem;
        font-weight: 400;
    }
    .bento__title em { font-style: italic; }
    .bento__earn .bento__title em { color: var(--amber-soft); }
    .bento__defend .bento__title em { color: var(--cyan-soft); }
    .bento__cash .bento__title em { color: var(--mint); }
    .bento__body {
        color: var(--text-secondary); font-size: var(--text-base);
        max-width: 32ch; line-height: 1.6;
    }

    .accent-divider {
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--border-subtle) 30%, var(--border-subtle) 70%, transparent);
    }

    /* — Defense list — */
    .defense {
        max-width: 76rem; margin: 0 auto;
        padding: 0 1.5rem;
        display: grid; gap: 2rem;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
    @media (max-width: 760px) { .defense { grid-template-columns: 1fr; } }
    .defense__col {
        background: var(--bg-panel);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        padding: 2rem;
    }
    .defense__col h3 {
        margin: 0 0 1.25rem;
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        text-transform: uppercase;
        letter-spacing: 0.18em;
        color: var(--text-tertiary);
        display: flex; align-items: center; gap: 0.5rem;
    }
    .defense__col h3::before {
        content: ''; display: inline-block;
        width: 18px; height: 1px;
        background: var(--text-tertiary);
    }
    .defense__list { list-style: none; padding: 0; margin: 0; }
    .defense__list li {
        display: flex; align-items: baseline; gap: 0.875rem;
        padding: 0.875rem 0;
        border-top: 1px solid var(--border-faint);
        font-size: var(--text-base);
    }
    .defense__list li:first-child { border-top: 0; padding-top: 0; }
    .defense__list code {
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        background: var(--bg-elev);
        color: var(--text-secondary);
        padding: 0.15rem 0.4rem;
        border-radius: 4px;
        flex-shrink: 0;
    }
    .defense__list--reject li { color: var(--text-tertiary); }
    .defense__list--reject .reject-name {
        text-decoration: line-through;
        text-decoration-color: var(--rose);
        text-decoration-thickness: 2px;
        color: var(--text-secondary);
    }
    .defense__list--accept li { color: var(--text-secondary); }
    .defense__list--accept code { color: var(--mint); }

    /* — Numbers strip — */
    .numbers {
        max-width: 76rem; margin: 0 auto;
        padding: 0 1.5rem;
        display: grid; gap: 1px;
        grid-template-columns: repeat(4, 1fr);
        background: var(--border-subtle);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        overflow: hidden;
    }
    @media (max-width: 760px) {
        .numbers { grid-template-columns: repeat(2, 1fr); }
    }
    .numbers__cell {
        background: var(--bg-panel);
        padding: 2rem 1.5rem;
    }
    .numbers__num {
        font-family: var(--font-display);
        font-size: clamp(2rem, 1.5rem + 2.5vw, 3.5rem);
        line-height: 1; letter-spacing: -0.02em;
        color: var(--amber-soft);
    }
    .numbers__num small {
        font-size: 0.45em;
        color: var(--text-tertiary);
        font-family: var(--font-mono);
        margin-left: 0.25rem;
        letter-spacing: 0;
    }
    .numbers__label {
        margin-top: 0.75rem;
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        color: var(--text-tertiary);
        text-transform: uppercase;
        letter-spacing: 0.16em;
    }

    /* — How strip — */
    .how {
        max-width: 76rem; margin: 0 auto;
        padding: 0 1.5rem;
        display: grid; gap: 1.25rem;
        grid-template-columns: repeat(4, 1fr);
        counter-reset: step;
    }
    @media (max-width: 760px) { .how { grid-template-columns: 1fr 1fr; } }
    .how__step {
        position: relative;
        padding: 1.5rem;
        background: var(--bg-panel);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-md);
    }
    .how__step::before {
        counter-increment: step;
        content: counter(step, decimal-leading-zero);
        position: absolute; top: 1rem; right: 1rem;
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        color: var(--text-quaternary);
        letter-spacing: 0.1em;
    }
    .how__step h4 {
        font-family: var(--font-display);
        font-size: 1.5rem;
        font-weight: 400;
        margin: 0 0 0.5rem;
        line-height: 1.1;
    }
    .how__step p {
        color: var(--text-secondary);
        font-size: var(--text-sm);
        margin: 0; line-height: 1.55;
    }

    /* — Affiliate-traffic conversion: above-the-fold value strip — */
    .value-strip {
        margin-top: 2.5rem;
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1px;
        background: var(--border-subtle);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-md);
        overflow: hidden;
    }
    @media (max-width: 720px) { .value-strip { grid-template-columns: repeat(2, 1fr); } }
    .value-strip__cell {
        background: var(--bg-panel);
        padding: 0.875rem 1rem;
        display: grid; gap: 0.15rem;
    }
    .value-strip__num {
        font-family: var(--font-display);
        font-size: 1.5rem;
        line-height: 1;
        color: var(--amber-soft);
        letter-spacing: -0.01em;
    }
    .value-strip__num small {
        font-family: var(--font-mono);
        font-size: 0.55em;
        color: var(--text-tertiary);
        margin-left: 0.2rem;
    }
    .value-strip__label {
        font-family: var(--font-mono);
        font-size: 0.6875rem;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: var(--text-tertiary);
    }

    .trust-row {
        display: flex; flex-wrap: wrap; align-items: center;
        gap: 1rem 1.25rem;
        margin-top: 1.25rem;
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        color: var(--text-tertiary);
    }
    .trust-row .badge {
        display: inline-flex; align-items: center; gap: 0.4rem;
        padding: 0.25rem 0.55rem;
        border-radius: 999px;
        background: var(--bg-elev);
        border: 1px solid var(--border-subtle);
        color: var(--text-secondary);
    }
    .trust-row .badge::before {
        content: '✓';
        color: var(--mint);
        font-weight: 700;
    }
</style>
@endpush

@section('content')

{{-- ─── Hero ─────────────────────────────────────────── --}}
<section class="hero">
    <div class="hero__inner">
        <div>
            <span class="eyebrow"><span class="eyebrow__pulse" aria-hidden="true"></span> FaucetPay-direct · Bot-clean inventory</span>

            <h1 class="display">
                Watch ads.<br>
                Earn <em>satoshi</em>.<br>
                <span class="struck">Wait days</span> Cash out fast.
            </h1>

            <p class="lede">
                Bots drain other PTC sites. SatPeek locks them out by design — so your clicks are worth more here. Withdraw to FaucetPay from <b style="color: var(--text-primary);">{{ number_format(config('satpeek.faucetpay.min_withdraw_sat')) }} sat</b>. No faucets, no fake balances.
            </p>

            <div class="cta-row">
                <a href="{{ route('register') }}" class="cta cta--primary">
                    Create free account <span class="cta__arrow">→</span>
                </a>
                <a href="#demo" class="cta cta--ghost">Try the captcha demo</a>
            </div>

            {{-- Above-the-fold value strip — 4 numbers a affiliate visitor scans in 2-3s --}}
            <div class="value-strip" aria-label="At a glance">
                <div class="value-strip__cell">
                    <span class="value-strip__num">{{ number_format(config('satpeek.faucetpay.min_withdraw_sat')) }}<small>sat</small></span>
                    <span class="value-strip__label">Min payout</span>
                </div>
                <div class="value-strip__cell">
                    <span class="value-strip__num">{{ (int) config('satpeek.referral.commission_pct') }}<small>%</small></span>
                    <span class="value-strip__label">Referral cut</span>
                </div>
                <div class="value-strip__cell">
                    <span class="value-strip__num">PTC<small>+ shortlinks</small></span>
                    <span class="value-strip__label">Daily inventory</span>
                </div>
                <div class="value-strip__cell">
                    <span class="value-strip__num">BTC<small>· DOGE · LTC</small></span>
                    <span class="value-strip__label">Withdraw via FaucetPay</span>
                </div>
            </div>

            <div class="trust-row">
                <span class="badge">No faucet · no farm</span>
                <span class="badge">2captcha cannot relay</span>
                <span class="badge">Auto-payouts from queue</span>
            </div>
        </div>

        {{-- Live captcha preview (interactive, client-only) --}}
        <figure class="demo" id="demo">
            <figcaption class="demo__chrome">
                <span class="demo__dots" aria-hidden="true"><span></span><span></span><span></span></span>
                <span class="demo__title">trajectory_trace · live preview</span>
                <span class="demo__status" id="demoStatus">● ready</span>
            </figcaption>
            <canvas id="demoCanvas" width="320" height="240" aria-label="Trajectory captcha demo. Drag from the green dot to the red goal following the moving target."></canvas>
            <div class="demo__instr">
                <span>Drag the green dot to the red goal.</span>
                <span class="demo__readout">
                    <span><b id="demoPoints">0</b>&nbsp;pts</span>
                    <span><b id="demoMs">0</b>&nbsp;ms</span>
                </span>
            </div>
            <div class="demo__instr" style="margin-top: 0.4rem;">
                <span class="demo__verdict" id="demoVerdict" data-state="">awaiting trace</span>
                <button type="button" class="btn-ghost" id="demoReset" style="font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary);">↺ reset</button>
            </div>
        </figure>
    </div>
</section>

{{-- ─── How it works (4 steps) ─────────────────────────── --}}
<section class="section" id="how">
    <header class="section__head">
        <div>
            <span class="section__index">/01 · the flow</span>
            <h2>How <em>it works</em></h2>
        </div>
        <p>From sign-up to first payout. Four steps. No shadow drains, no opaque redirects.</p>
    </header>
    <div class="how">
        <div class="how__step">
            <h4>Sign up</h4>
            <p>Email, password, FaucetPay address. We fingerprint your browser to anchor the rest of the session.</p>
        </div>
        <div class="how__step">
            <h4>Trace</h4>
            <p>Drag a token along a server-issued curve. Pressure, jitter and dwell are scored against a per-issue seed.</p>
        </div>
        <div class="how__step">
            <h4>View</h4>
            <p>PTC ads from BitcoTask and shortlinks pay out in sats. A heartbeat protocol confirms you actually watched.</p>
        </div>
        <div class="how__step">
            <h4>Cash out</h4>
            <p>Trigger a withdrawal above {{ number_format(config('satpeek.faucetpay.min_withdraw_sat')) }} sat. FaucetPay processes the payout from queue.</p>
        </div>
    </div>
</section>

{{-- ─── Bento value props ──────────────────────────────── --}}
<section class="section">
    <header class="section__head">
        <div>
            <span class="section__index">/02 · what you get</span>
            <h2>Earn. Defend. <em>Get paid.</em></h2>
        </div>
        <p>Three pillars. None of them depend on you completing a math captcha generated five years ago.</p>
    </header>
    <div class="bento">
        <article class="bento__card bento__card--earn bento__earn">
            <span class="bento__num">A · earn</span>
            <h3 class="bento__title">View ads, <em>collect sats</em>.</h3>
            <p class="bento__body">Curated PTC inventory from BitcoTask plus our own offer wall. Each ad ships with a server-bound timer — visibility-spoofing tricks get rejected before reward credit.</p>
        </article>
        <article class="bento__card bento__card--defend bento__defend">
            <span class="bento__num">B · defend</span>
            <h3 class="bento__title">A captcha relays <em>can't see.</em></h3>
            <p class="bento__body">Trajectory + jitter + jerk-entropy + completion dwell + fingerprint binding + an 800 ms–25 s response window. No single image to ship to a worker.</p>
        </article>
        <article class="bento__card bento__card--cash bento__cash">
            <span class="bento__num">C · payouts</span>
            <h3 class="bento__title">Direct to <em>FaucetPay</em>.</h3>
            <p class="bento__body" style="max-width: 48ch;">Every withdrawal flows through a queue. Trust-tier accounts settle automatically; suspect tiers go through human review before a single satoshi leaves the platform.</p>
        </article>
    </div>
</section>

{{-- ─── Defense narrative ──────────────────────────────── --}}
<section class="section" id="defense">
    <header class="section__head">
        <div>
            <span class="section__index">/03 · the defense</span>
            <h2>Built to <em>reject the toolkit</em>.</h2>
        </div>
        <p>We watched four open-source bots farm the major PTC sites. SatPeek is what happens when those bots become a test suite instead of a problem.</p>
    </header>
    <div class="defense">
        <div class="defense__col">
            <h3>What we reject</h3>
            <ul class="defense__list defense__list--reject">
                <li><code>×</code> <span><span class="reject-name">2captcha Coordinates API</span> — a single (x, y) is one of fifty samples, not the answer.</span></li>
                <li><code>×</code> <span><span class="reject-name">OpenRouter VLM</span> — there is no static frame to ship to a vision model.</span></li>
                <li><code>×</code> <span><span class="reject-name">Florence-2 LoRA</span> — every challenge is a fresh seed; corpus collection never converges.</span></li>
                <li><code>×</code> <span><span class="reject-name">Headless Playwright</span> — pressure is zero, Δt is uniform, jerk entropy collapses.</span></li>
                <li><code>×</code> <span><span class="reject-name">curl_cffi TLS spoof</span> — JA4 is cross-checked against UA + Sec-CH-UA + canvas hash.</span></li>
            </ul>
        </div>
        <div class="defense__col">
            <h3>What we score</h3>
            <ul class="defense__list defense__list--accept">
                <li><code>+</code> Frechet distance against a per-issue parametric curve.</li>
                <li><code>+</code> Δt jitter ratio &gt; 0.12 — humans wobble, scripts don't.</li>
                <li><code>+</code> Jerk entropy &gt; 1.5 bits — Bezier replays collapse here.</li>
                <li><code>+</code> 150 ms+ completion dwell at the goal marker.</li>
                <li><code>+</code> Heartbeat cadence with ±jitter, fingerprint &amp; ASN cross-checks.</li>
            </ul>
        </div>
    </div>
</section>

{{-- ─── Numbers strip ──────────────────────────────────── --}}
<section class="section" id="numbers">
    <header class="section__head">
        <div>
            <span class="section__index">/04 · by the numbers</span>
            <h2>The <em>solve window</em>.</h2>
        </div>
        <p>Tunable in <code style="font-family: var(--font-mono); font-size: 0.9em; color: var(--cyan-soft);">config/satpeek.php</code>. These defaults exclude every relay service we tested.</p>
    </header>
    <div class="numbers">
        <div class="numbers__cell">
            <div class="numbers__num">{{ (int) (config('satpeek.captcha.min_solve_ms') / 1000 * 1000) }}<small>ms</small></div>
            <div class="numbers__label">min solve · scripts below</div>
        </div>
        <div class="numbers__cell">
            <div class="numbers__num">{{ (int) (config('satpeek.captcha.max_solve_ms') / 1000) }}<small>s</small></div>
            <div class="numbers__label">max solve · relays beyond</div>
        </div>
        <div class="numbers__cell">
            <div class="numbers__num">7<small>signals</small></div>
            <div class="numbers__label">in the trust score</div>
        </div>
        <div class="numbers__cell">
            <div class="numbers__num">~60<small>Hz</small></div>
            <div class="numbers__label">sample rate captured</div>
        </div>
    </div>
</section>

{{-- ─── Advertise on the same account ──────────────────────── --}}
<section class="section" id="advertise">
    <header class="section__head">
        <div>
            <span class="section__index">/05 · advertise too</span>
            <h2>Earn here. <em>Advertise here.</em> One account.</h2>
        </div>
        <p>SatPeek collapses publisher and advertiser into the same account. Pay other earners in sats to view your affiliate link, your offer, your token launch — straight from the balance you built up viewing ads.</p>
    </header>
    <div class="bento" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
        <article class="bento__card bento__earn" style="grid-column: span 1;">
            <span class="bento__num">step 1 · earn</span>
            <h3 class="bento__title" style="font-size: clamp(1.25rem, 0.75rem + 1.2vw, 1.6rem);">View ads, stack <em>sats</em>.</h3>
            <p class="bento__body">Same trajectory captcha, same heartbeat-anchored timer, same FaucetPay payout — your earner balance is also your ad budget.</p>
        </article>
        <article class="bento__card bento__defend" style="grid-column: span 1;">
            <span class="bento__num">step 2 · launch</span>
            <h3 class="bento__title" style="font-size: clamp(1.25rem, 0.75rem + 1.2vw, 1.6rem);">Submit a <em>campaign</em>.</h3>
            <p class="bento__body">Pick a per-view bid (sat), choose how many views to buy, drop your URL. Cost is locked in upfront from your balance — no surprise overruns.</p>
        </article>
        <article class="bento__card bento__cash" style="grid-column: span 1; padding-bottom: 1.75rem;">
            <span class="bento__num">step 3 · serve</span>
            <h3 class="bento__title" style="font-size: clamp(1.25rem, 0.75rem + 1.2vw, 1.6rem);">Reach <em>real humans</em>.</h3>
            <p class="bento__body">Your ad goes through the same captcha + bot-tier filter that protects payouts — every recorded view is a real person, not a script.</p>
        </article>
    </div>
    <div class="container" style="margin-top: 1.5rem; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 1rem; align-items: baseline;">
        <p style="color: var(--text-tertiary); font-family: var(--font-mono); font-size: var(--text-xs); margin: 0; letter-spacing: 0.04em;">
            Cost = reward × {{ 100 + (int) config('satpeek.ads.commission_pct') }}% (incl. {{ (int) config('satpeek.ads.commission_pct') }}% platform fee) · every campaign is reviewed before going live · bid {{ (int) config('satpeek.ads.reward_min_sat') }}–{{ (int) config('satpeek.ads.reward_max_sat') }} sat / view · {{ number_format((int) config('satpeek.ads.views_min')) }}+ views
        </p>
        <a href="{{ auth()->check() ? route('advertise.create') : route('register') }}" class="cta cta--ghost" style="font-size: var(--text-sm);">
            {{ auth()->check() ? 'Launch a campaign' : 'Create a free account' }} <span class="cta__arrow">→</span>
        </a>
    </div>
</section>

@endsection

@push('body')
<script>
/**
 * Live trajectory captcha preview — entirely client-side, no backend round-trip.
 * Renders the same curve / start / goal / moving target as the production
 * provider so visitors see the actual challenge mechanic before signing up.
 */
(() => {
    const canvas = document.getElementById('demoCanvas');
    const status = document.getElementById('demoStatus');
    const verdict = document.getElementById('demoVerdict');
    const ptsEl = document.getElementById('demoPoints');
    const msEl = document.getElementById('demoMs');
    const reset = document.getElementById('demoReset');
    if (!canvas || !canvas.getContext) return;
    const ctx = canvas.getContext('2d');

    // Render at the canvas's CSS-resolved size while keeping the logical 320×240
    // coordinate system. Re-tuning on resize keeps the lines crisp on retina.
    function fitCanvas() {
        const rect = canvas.getBoundingClientRect();
        const dpr = Math.max(1, window.devicePixelRatio || 1);
        canvas.width = Math.round(rect.width * dpr);
        canvas.height = Math.round(rect.height * dpr);
        ctx.setTransform(dpr * rect.width / 320, 0, 0, dpr * rect.height / 240, 0, 0);
    }
    fitCanvas();
    window.addEventListener('resize', fitCanvas);

    const W = 320, H = 240;
    let challenge = null;
    let points = [];
    let startedAt = 0;
    let capturing = false;
    let result = null;

    function newChallenge() {
        const rng = Math.random;
        const startX = 30 + Math.floor(rng() * 50);
        const startY = 60 + Math.floor(rng() * 100);
        const endX = W - 30 - Math.floor(rng() * 50);
        const endY = 70 + Math.floor(rng() * 100);
        const amplitude = 20 + Math.floor(rng() * 40);
        const frequency = 1 + Math.floor(rng() * 3);
        const durationMs = 6500 + Math.floor(rng() * 2500);
        const curves = ['linear', 'sine', 'lissajous'];
        challenge = {
            curve: curves[Math.floor(rng() * curves.length)],
            startX, startY, endX, endY, amplitude, frequency, durationMs,
            issuedAt: performance.now()
        };
        points = [];
        startedAt = 0;
        result = null;
        verdict.textContent = 'awaiting trace';
        verdict.dataset.state = '';
        ptsEl.textContent = '0';
        msEl.textContent = '0';
        status.textContent = '● ready';
        status.style.color = 'var(--mint)';
    }

    function curveAt(u) {
        const c = challenge;
        const x = c.startX + (c.endX - c.startX) * u;
        const baseY = c.startY + (c.endY - c.startY) * u;
        let y = baseY;
        if (c.curve === 'sine')
            y = baseY + Math.sin(u * Math.PI * 2 * c.frequency) * c.amplitude;
        else if (c.curve === 'lissajous')
            y = baseY + Math.sin(u * Math.PI * 2 * c.frequency) * c.amplitude * Math.cos(u * Math.PI);
        return [x, y];
    }

    function draw(now) {
        const c = challenge;
        if (!c) { requestAnimationFrame(draw); return; }
        ctx.clearRect(0, 0, W, H);

        // Subtle grid
        ctx.fillStyle = '#070b14';
        ctx.fillRect(0, 0, W, H);
        ctx.strokeStyle = 'rgba(29, 38, 52, 0.55)';
        ctx.lineWidth = 1;
        for (let x = 0; x < W; x += 24) {
            ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, H); ctx.stroke();
        }
        for (let y = 0; y < H; y += 24) {
            ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke();
        }

        // Reference curve (subtle)
        ctx.strokeStyle = 'rgba(103, 232, 249, 0.18)';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        for (let i = 0; i <= 80; i++) {
            const u = i / 80;
            const [x, y] = curveAt(u);
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        }
        ctx.stroke();

        // Goal marker
        ctx.fillStyle = '#fb7185';
        ctx.beginPath();
        ctx.arc(c.endX, c.endY, 12, 0, Math.PI * 2);
        ctx.fill();

        // Start marker
        ctx.fillStyle = '#34d399';
        ctx.beginPath();
        ctx.arc(c.startX, c.startY, 8, 0, Math.PI * 2);
        ctx.fill();

        // Trail
        if (points.length > 1) {
            ctx.strokeStyle = 'rgba(252, 211, 77, 0.95)';
            ctx.lineWidth = 2.25;
            ctx.lineCap = 'round'; ctx.lineJoin = 'round';
            ctx.beginPath();
            ctx.moveTo(points[0].x, points[0].y);
            for (let i = 1; i < points.length; i++) ctx.lineTo(points[i].x, points[i].y);
            ctx.stroke();
        }

        // Moving target — uses time since challenge issue, modulo loop.
        const elapsed = capturing
            ? (performance.now() - startedAt)
            : ((performance.now() - c.issuedAt) % c.durationMs);
        const u = Math.min(1, elapsed / c.durationMs);
        const [tx, ty] = curveAt(u);
        ctx.fillStyle = '#fbbf24';
        ctx.shadowColor = 'rgba(251, 191, 36, 0.6)';
        ctx.shadowBlur = 12;
        ctx.beginPath();
        ctx.arc(tx, ty, 7, 0, Math.PI * 2);
        ctx.fill();
        ctx.shadowBlur = 0;

        requestAnimationFrame(draw);
    }
    requestAnimationFrame(draw);

    function pointerXY(e) {
        const rect = canvas.getBoundingClientRect();
        const sx = W / rect.width;
        const sy = H / rect.height;
        return {
            x: (e.clientX - rect.left) * sx,
            y: (e.clientY - rect.top) * sy
        };
    }

    canvas.addEventListener('pointerdown', (e) => {
        if (!challenge) return;
        if (result) newChallenge();
        capturing = true;
        startedAt = performance.now();
        canvas.setPointerCapture(e.pointerId);
        const p = pointerXY(e);
        points.push({ x: p.x, y: p.y, t: 0, pressure: e.pressure || 0 });
        status.textContent = '● capturing';
        status.style.color = 'var(--cyan)';
        e.preventDefault();
    });
    canvas.addEventListener('pointermove', (e) => {
        if (!capturing) return;
        const p = pointerXY(e);
        points.push({
            x: p.x, y: p.y,
            t: performance.now() - startedAt,
            pressure: e.pressure || 0
        });
        ptsEl.textContent = String(points.length);
        msEl.textContent = String(Math.round(performance.now() - startedAt));
    });
    canvas.addEventListener('pointerup', (e) => {
        if (!capturing) return;
        capturing = false;
        try { canvas.releasePointerCapture(e.pointerId); } catch {}
        evaluate();
    });
    canvas.addEventListener('pointercancel', () => {
        capturing = false;
        evaluate();
    });

    /**
     * Lightweight client-side scoring. Mirrors the server's checks well enough
     * to give visitors honest feedback. The server-side verifier remains the
     * source of truth in production.
     */
    function evaluate() {
        const c = challenge;
        const n = points.length;
        const ms = n ? points[n - 1].t : 0;
        if (n < 12) return setVerdict('fail', 'too_few_points · keep the drag going');

        // shape distance — sampled Frechet
        let maxDist = 0;
        for (const pt of points) {
            const u = Math.max(0, Math.min(1, pt.t / c.durationMs));
            const [cx, cy] = curveAt(u);
            const d = Math.hypot(cx - pt.x, cy - pt.y);
            if (d > maxDist) maxDist = d;
        }
        if (maxDist > 64) return setVerdict('fail', 'shape_mismatch · follow the line');

        // dt jitter
        const dts = [];
        for (let i = 1; i < n; i++) dts.push(points[i].t - points[i - 1].t);
        const median = [...dts].sort((a, b) => a - b)[Math.floor(dts.length / 2)] || 0;
        const mean = dts.reduce((a, b) => a + b, 0) / dts.length;
        const stdev = Math.sqrt(dts.reduce((s, d) => s + (d - mean) ** 2, 0) / dts.length);
        const jitterRatio = median > 0 ? stdev / median : 0;

        if (ms < 800)  return setVerdict('fail', 'too_fast · ' + ms + ' ms < 800 ms');
        if (ms > 25000) return setVerdict('fail', 'too_slow_relay · ' + ms + ' ms > 25 s');

        const dwellTail = points.slice(-Math.min(8, n));
        const dx = dwellTail.reduce((acc, p) => acc + Math.abs(p.x - dwellTail[dwellTail.length - 1].x), 0);
        if (dx > 24) return setVerdict('fail', 'no_completion_dwell · pause at the goal');

        if (jitterRatio < 0.12) return setVerdict('fail', 'dt_too_uniform · ' + jitterRatio.toFixed(2));

        return setVerdict('pass', 'ok · jitter=' + jitterRatio.toFixed(2) + ' · ' + ms + 'ms');
    }

    function setVerdict(state, msg) {
        result = state;
        verdict.dataset.state = state;
        verdict.textContent = msg;
        status.textContent = state === 'pass' ? '● verified' : '● rejected';
        status.style.color = state === 'pass' ? 'var(--mint)' : 'var(--rose)';
    }

    reset.addEventListener('click', newChallenge);
    newChallenge();
})();
</script>
@endpush
