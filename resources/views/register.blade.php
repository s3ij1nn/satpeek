@extends('layouts.app')

@push('head')
<style>
    .signup {
        max-width: 36rem; margin: 0 auto;
        padding: clamp(3rem, 2rem + 5vw, 6rem) 1.5rem;
    }
    .signup__eyebrow {
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        text-transform: uppercase;
        letter-spacing: 0.18em;
        color: var(--text-tertiary);
    }
    .signup h1 {
        font-family: var(--font-display);
        font-size: var(--display-lg);
        line-height: 1.02;
        letter-spacing: -0.02em;
        font-weight: 400;
        margin: 0.75rem 0 1rem;
    }
    .signup h1 em { font-style: italic; color: var(--amber-soft); }
    .signup p.lede {
        color: var(--text-secondary);
        font-size: var(--text-lg);
        margin: 0 0 2rem;
        line-height: 1.6;
    }

    .form-card {
        background: var(--bg-panel);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        padding: 2rem;
        display: grid;
        /* `minmax(0, 1fr)` prevents children whose intrinsic content is wider
           than the column (e.g. a <canvas> whose .width attribute grew via JS)
           from blowing the form-card past its parent. */
        grid-template-columns: minmax(0, 1fr);
        gap: 1rem;
    }
    .form-card > * { min-width: 0; }
    .field { display: grid; gap: 0.4rem; }
    .field label {
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        text-transform: uppercase;
        letter-spacing: 0.14em;
        color: var(--text-tertiary);
    }
    .field input {
        background: var(--bg-canvas);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius-md);
        padding: 0.75rem 0.875rem;
        color: var(--text-primary);
        font: inherit;
        transition: border-color var(--dur-fast) var(--ease-out-expo);
    }
    .field input:focus {
        outline: 0;
        border-color: var(--amber);
        box-shadow: 0 0 0 3px var(--amber-glow);
    }
    .field__hint {
        font-size: var(--text-xs);
        color: var(--text-tertiary);
    }
    .field__error {
        font-size: var(--text-xs);
        color: var(--rose);
    }

    .honeypot { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }

    /* Captcha embed */
    .captcha-block {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 0.6rem;
        padding: 1rem;
        min-width: 0;
        background: var(--bg-canvas);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius-md);
    }
    .captcha-block > * { min-width: 0; }
    .captcha-block__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        min-height: 1.6rem;          /* lock height so the canvas never shifts */
        font-family: var(--font-mono);
        font-size: var(--text-xs);
        color: var(--text-tertiary);
        text-transform: uppercase;
        letter-spacing: 0.14em;
    }
    .captcha-block__head > span:first-child { flex-shrink: 0; }
    .captcha-block__status {
        padding: 0.15rem 0.5rem;
        border-radius: var(--radius-sm);
        background: var(--bg-elev-2);
        color: var(--text-tertiary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 65%;
        min-width: 6rem;
        text-align: center;
    }
    .captcha-block__status[data-state="ready"] { background: rgba(103, 232, 249, 0.12); color: var(--cyan); }
    .captcha-block__status[data-state="capturing"] { background: rgba(252, 211, 77, 0.12); color: var(--amber-soft); }
    .captcha-block__status[data-state="pass"] { background: rgba(52, 211, 153, 0.12); color: var(--mint); }
    .captcha-block__status[data-state="fail"] { background: rgba(251, 113, 133, 0.12); color: var(--rose); }
    .captcha-block canvas {
        display: block; width: 100%; max-width: 100%; height: auto;
        aspect-ratio: 320 / 200;
        background: #060912;
        border-radius: var(--radius-sm);
        cursor: crosshair;
        touch-action: none;
    }
    .captcha-block__instr {
        display: flex; justify-content: space-between; align-items: center;
        gap: 0.75rem;
        min-height: 1.4rem;          /* lock height to prevent layout shifts */
        font-family: var(--font-mono); font-size: 0.6875rem;
        color: var(--text-tertiary);
    }
    .captcha-block__instr > span:first-child {
        flex: 1 1 auto;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .captcha-block__reset {
        background: none; border: 0; color: var(--text-tertiary);
        font: inherit; cursor: pointer;
    }
    .captcha-block__reset:hover { color: var(--text-secondary); }

    .signup__benefits {
        margin-top: 2rem;
        display: grid; grid-template-columns: repeat(2, 1fr);
        gap: 1px;
        background: var(--border-subtle);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-md);
        overflow: hidden;
    }
    @media (max-width: 540px) { .signup__benefits { grid-template-columns: 1fr; } }
    .signup__benefits div {
        background: var(--bg-panel);
        padding: 0.875rem 1rem;
    }
    .signup__benefits .num {
        font-family: var(--font-display);
        font-size: 1.375rem;
        color: var(--amber-soft);
        line-height: 1;
    }
    .signup__benefits .label {
        font-family: var(--font-mono);
        font-size: 0.65rem;
        color: var(--text-tertiary);
        text-transform: uppercase;
        letter-spacing: 0.12em;
        margin-top: 0.4rem;
    }

    .alert--ok {
        margin-bottom: 1.5rem;
        padding: 1rem 1.25rem;
        border-radius: var(--radius-md);
        background: rgba(52, 211, 153, 0.08);
        border: 1px solid rgba(52, 211, 153, 0.3);
        color: var(--mint);
        font-size: var(--text-sm);
    }
    .alert--err {
        margin-bottom: 1.25rem;
        padding: 0.875rem 1.125rem;
        border-radius: var(--radius-md);
        background: rgba(251, 113, 133, 0.08);
        border: 1px solid rgba(251, 113, 133, 0.3);
        color: var(--rose);
        font-size: var(--text-sm);
    }

    /* Success panel — replaces the form on submit success.
       Layout strategy: hero (centered) → divider → details (left-aligned key/value
       pairs). Short sentences are folded into compact rows so nothing wraps awkwardly
       inside the ~480 px content column. */
    .signup__success {
        background: linear-gradient(160deg, var(--bg-elev) 0%, var(--bg-panel) 100%);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        padding: 2.5rem 2rem;
        box-shadow: 0 30px 60px -20px rgba(0, 0, 0, 0.55);
    }
    .signup__success-hero {
        text-align: center;
        padding-bottom: 1.5rem;
    }
    .signup__success-mark {
        width: 56px; height: 56px; border-radius: 50%;
        background: rgba(52, 211, 153, 0.12);
        border: 1px solid rgba(52, 211, 153, 0.5);
        display: inline-flex; align-items: center; justify-content: center;
        margin-bottom: 1rem;
        color: var(--mint);
        font-size: 1.75rem; line-height: 1;
    }
    .signup__success h2 {
        font-family: var(--font-display);
        font-size: clamp(1.75rem, 1rem + 2vw, 2.5rem);
        font-weight: 400;
        letter-spacing: -0.01em;
        line-height: 1.05;
        margin: 0 0 0.75rem;
    }
    .signup__success h2 em { font-style: italic; color: var(--mint); }
    .signup__success-lead {
        color: var(--text-secondary);
        font-size: var(--text-sm);
        margin: 0 0 1rem;
        line-height: 1.5;
    }
    .signup__success .email-pill {
        display: inline-block;
        padding: 0.45rem 0.85rem;
        background: var(--bg-canvas);
        border: 1px solid var(--border-subtle);
        border-radius: 999px;
        font-family: var(--font-mono);
        font-size: var(--text-sm);
        color: var(--text-primary);
        word-break: break-all;
        max-width: 100%;
    }

    .signup__success-body {
        border-top: 1px solid var(--border-subtle);
        padding-top: 1.5rem;
        max-width: 28rem;
        margin: 0 auto;
    }
    .signup__success-msg {
        color: var(--text-secondary);
        font-size: var(--text-sm);
        line-height: 1.65;
        margin: 0 0 1.25rem;
    }

    /* Two-column key / value list — labels never wrap, values flow naturally. */
    .next-steps {
        list-style: none;
        padding: 0;
        margin: 0 0 1.5rem;
        display: grid;
        gap: 0.5rem;
        font-size: var(--text-sm);
    }
    .next-steps li {
        display: grid;
        grid-template-columns: 6.5rem 1fr;
        gap: 0.85rem;
        align-items: baseline;
        padding: 0.5rem 0;
        border-top: 1px solid var(--border-faint);
    }
    .next-steps li:first-child { border-top: 0; padding-top: 0; }
    .next-steps dt,
    .next-steps .label {
        font-family: var(--font-mono);
        font-size: 0.6875rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: var(--text-tertiary);
        white-space: nowrap;
    }
    .next-steps dd,
    .next-steps .value {
        margin: 0;
        color: var(--text-secondary);
        line-height: 1.5;
        word-break: break-word;
    }
    .next-steps .value strong { color: var(--text-primary); font-weight: 500; }
    .next-steps .value code {
        font-family: var(--font-mono);
        font-size: 0.8125rem;
        color: var(--text-primary);
        background: var(--bg-canvas);
        padding: 0.1rem 0.35rem;
        border-radius: 4px;
    }

    .signup__success-resend {
        text-align: center;
        font-size: var(--text-xs);
        color: var(--text-tertiary);
        margin: 0;
    }
    .signup__success-resend a {
        color: var(--amber-soft);
        text-decoration: underline;
    }
</style>
@endpush

@section('content')
<section class="signup">
    <span class="signup__eyebrow">/ free account · beta access</span>
    <h1>Reserve your <em>spot</em>.</h1>
    <p class="lede">
        SatPeek is in private beta while the trust-tier system warms up. Drop your email and FaucetPay address — we'll send you the activation link the moment the queue opens.
    </p>

    {{-- Inline error banner (populated by JS on AJAX failure). --}}
    <div id="signupError" class="alert--err" role="alert" style="display:none;"></div>

    {{-- Success panel — JS reveals this and hides the form when registration succeeds. --}}
    <section id="signupSuccess" class="signup__success" role="status" aria-live="polite" style="display:none;">
        <div class="signup__success-hero">
            <div class="signup__success-mark" aria-hidden="true">✓</div>
            <h2>Check your <em>inbox</em>.</h2>
            <p class="signup__success-lead">A confirmation email is on its way to:</p>
            <div class="email-pill" id="signupSuccessEmail">—</div>
        </div>
        <div class="signup__success-body">
            <p class="signup__success-msg" id="signupSuccessMessage">
                Open the email to verify your address. We'll send the activation link to the same inbox the moment SatPeek opens.
            </p>
            <ul class="next-steps">
                <li>
                    <span class="label">Sender</span>
                    <span class="value"><code>no-reply@satpeek.com</code></span>
                </li>
                <li>
                    <span class="label">Subject</span>
                    <span class="value"><strong>You're on the SatPeek waitlist</strong></span>
                </li>
                <li>
                    <span class="label">Missing?</span>
                    <span class="value">Check your spam or promotions folder.</span>
                </li>
            </ul>
            <p class="signup__success-resend">
                Wrong email? <a href="{{ route('register') }}">Sign up again →</a>
            </p>
        </div>
    </section>

    {{-- Server-side flashes for non-JS / fallback navigation. --}}
    @if (session('status') && ! request()->ajax())
        <div class="alert--ok">{{ session('status') }}</div>
    @endif

    @error('captcha')
        <div class="alert--err">{{ $message }}</div>
    @enderror

    <form class="form-card" method="POST" action="{{ route('register.store') }}" id="signupForm" novalidate>
        @csrf

        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required
                   placeholder="you@inbox.com"
                   value="{{ old('email') }}">
            @error('email') <span class="field__error">{{ $message }}</span> @enderror
        </div>

        <div class="field">
            <label for="faucetpay_email">FaucetPay address (optional)</label>
            <input id="faucetpay_email" name="faucetpay_email" type="email" autocomplete="off"
                   placeholder="same as your FaucetPay login"
                   value="{{ old('faucetpay_email') }}">
            <span class="field__hint">Pre-fills your withdrawal account when the platform opens. Skip if you'll add it later.</span>
            @error('faucetpay_email') <span class="field__error">{{ $message }}</span> @enderror
        </div>

        <div class="field">
            <label for="referral_code">Referral code (optional)</label>
            <input id="referral_code" name="referral_code" type="text" maxlength="16"
                   placeholder="from a friend"
                   value="{{ old('referral_code', request()->query('ref')) }}">
            @error('referral_code') <span class="field__error">{{ $message }}</span> @enderror
        </div>

        {{-- Trajectory captcha — required for submission --}}
        <div class="captcha-block">
            <div class="captcha-block__head">
                <span>Trajectory captcha</span>
                <span class="captcha-block__status" id="captchaStatus" data-state="ready">● ready</span>
            </div>
            <canvas id="captchaCanvas" width="320" height="200"
                aria-label="Drag from the green dot to the red goal following the moving yellow target."></canvas>
            <div class="captcha-block__instr">
                <span>From the green dot, follow the yellow target with your finger / mouse. Release inside the red ring — it turns green when you're in.</span>
                <button type="button" class="captcha-block__reset" id="captchaReset">↺ new challenge</button>
            </div>
        </div>

        <input type="hidden" name="captcha_challenge_id" id="captchaChallengeId">
        <input type="hidden" name="captcha_points" id="captchaPoints">
        <input type="hidden" name="source" value="{{ request()->headers->get('referer', '') }}">

        <div class="honeypot" aria-hidden="true">
            <label>Website (leave empty)<input type="text" name="website" tabindex="-1"></label>
        </div>

        <button type="submit" class="cta cta--primary" id="signupSubmit"
                style="margin-top: 0.5rem; justify-content: center;">
            Reserve my spot <span class="cta__arrow">→</span>
        </button>
    </form>

    <div class="signup__benefits" aria-label="What you get on launch day">
        <div>
            <div class="num">{{ (int) config('satpeek.referral.commission_pct') }}<small style="font-family: var(--font-mono); font-size: 0.55em; color: var(--text-tertiary); margin-left: 0.2rem;">%</small></div>
            <div class="label">Lifetime referral cut</div>
        </div>
        <div>
            <div class="num">{{ number_format(config('satpeek.faucetpay.min_withdraw_sat')) }}<small style="font-family: var(--font-mono); font-size: 0.55em; color: var(--text-tertiary); margin-left: 0.2rem;">sat</small></div>
            <div class="label">Min withdrawal</div>
        </div>
    </div>

    <p style="margin-top: 2rem; font-size: var(--text-xs); color: var(--text-tertiary); text-align: center;">
        We never share your email. Unsubscribe link in every message.
    </p>
</section>
@endsection

@push('body')
<script>
/**
 * Live trajectory captcha — fetches a real challenge from /api/captcha/issue,
 * captures the user's pointer trajectory, and submits the trace alongside
 * the waitlist form. Server-side ChallengeVerifier rejects submissions that
 * do not match shape / Δt jitter / completion dwell, with the per-issue seed
 * binding making relay services impossible to reuse a previous solve.
 */
(() => {
    const FP_KEY = 'satpeek_fingerprint';
    let fingerprint = localStorage.getItem(FP_KEY);
    if (!fingerprint) {
        fingerprint = (crypto.randomUUID && crypto.randomUUID()) ||
            (Date.now().toString(36) + Math.random().toString(36).slice(2));
        localStorage.setItem(FP_KEY, fingerprint);
    }

    const canvas = document.getElementById('captchaCanvas');
    const status = document.getElementById('captchaStatus');
    const resetBtn = document.getElementById('captchaReset');
    const challengeIdEl = document.getElementById('captchaChallengeId');
    const pointsEl = document.getElementById('captchaPoints');
    const form = document.getElementById('signupForm');
    const submitBtn = document.getElementById('signupSubmit');
    if (!canvas || !canvas.getContext) return;
    const ctx = canvas.getContext('2d');

    let challenge = null, payload = null, points = [], capturing = false, startedAt = 0, animId = null;

    function fitCanvas() {
        const rect = canvas.getBoundingClientRect();
        const dpr = Math.max(1, window.devicePixelRatio || 1);
        canvas.width = Math.round(rect.width * dpr);
        canvas.height = Math.round(rect.height * dpr);
        const targetW = challenge?.payload?.canvas?.w ?? 320;
        const targetH = challenge?.payload?.canvas?.h ?? 240;
        ctx.setTransform(dpr * rect.width / targetW, 0, 0, dpr * rect.height / targetH, 0, 0);
    }
    window.addEventListener('resize', fitCanvas);

    function setStatus(state, text) {
        status.dataset.state = state;
        status.textContent = '● ' + text;
    }

    async function issueChallenge() {
        setStatus('ready', 'fetching challenge...');
        points = [];
        challengeIdEl.value = '';
        pointsEl.value = '';
        try {
            const res = await fetch('/api/captcha/issue', {
                headers: { 'Accept': 'application/json', 'X-SP-Fingerprint': fingerprint },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error('issue_failed:' + res.status);
            challenge = await res.json();
            payload = challenge.payload;
            challengeIdEl.value = challenge.challengeId;
            startedAt = 0;
            fitCanvas();
            startTargetAnimation();
            setStatus('ready', 'drag from the green dot');
        } catch (e) {
            setStatus('fail', 'could not load captcha — refresh the page');
        }
    }

    function curveAt(u) {
        const p = payload;
        const x = p.start.x + (p.end.x - p.start.x) * u;
        const baseY = p.start.y + (p.end.y - p.start.y) * u;
        let y = baseY;
        if (p.curve === 'sine') y = baseY + Math.sin(u * Math.PI * 2 * p.frequency) * p.amplitude;
        else if (p.curve === 'lissajous') y = baseY + Math.sin(u * Math.PI * 2 * p.frequency) * p.amplitude * Math.cos(u * Math.PI);
        return [x, y];
    }

    function startTargetAnimation() {
        const start = performance.now();
        if (animId) cancelAnimationFrame(animId);
        const tick = (now) => {
            if (!payload) return;
            const elapsed = capturing ? (now - startedAt) : ((now - start) % payload.durationMs);
            const u = Math.min(1, elapsed / payload.durationMs);
            draw(u);
            animId = requestAnimationFrame(tick);
        };
        animId = requestAnimationFrame(tick);
    }

    function draw(targetU) {
        if (!payload) return;
        const W = payload.canvas.w, H = payload.canvas.h;
        ctx.clearRect(0, 0, W, H);
        ctx.fillStyle = '#060912';
        ctx.fillRect(0, 0, W, H);
        ctx.strokeStyle = 'rgba(29, 38, 52, 0.55)';
        ctx.lineWidth = 1;
        for (let x = 0; x < W; x += 24) { ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, H); ctx.stroke(); }
        for (let y = 0; y < H; y += 24) { ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke(); }

        // Reference curve hint (very faint)
        ctx.strokeStyle = 'rgba(103, 232, 249, 0.15)';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        for (let i = 0; i <= 80; i++) {
            const u = i / 80;
            const [x, y] = curveAt(u);
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        }
        ctx.stroke();

        // Detect "in goal zone" — when the latest user point lands inside the
        // goal radius, the goal marker turns green to confirm a successful
        // landing. This makes it obvious WHERE to release the pointer.
        const last = points.length ? points[points.length - 1] : null;
        const goalRadius = 16;
        const inGoal = last && Math.hypot(last.x - payload.end.x, last.y - payload.end.y) <= goalRadius;

        // Goal — pulses red until the user reaches it, then locks green.
        if (inGoal) {
            ctx.fillStyle = '#22c55e';
            ctx.shadowColor = 'rgba(34, 197, 94, 0.65)';
            ctx.shadowBlur = 14;
        } else {
            ctx.fillStyle = '#fb7185';
        }
        ctx.beginPath(); ctx.arc(payload.end.x, payload.end.y, 14, 0, Math.PI * 2); ctx.fill();
        ctx.shadowBlur = 0;
        // Goal ring (outer halo so the target is unmistakable on a busy canvas)
        ctx.strokeStyle = inGoal ? 'rgba(34, 197, 94, 0.8)' : 'rgba(251, 113, 133, 0.55)';
        ctx.lineWidth = 1.5;
        ctx.beginPath(); ctx.arc(payload.end.x, payload.end.y, 22, 0, Math.PI * 2); ctx.stroke();

        // Start
        ctx.fillStyle = '#22c55e';
        ctx.beginPath(); ctx.arc(payload.start.x, payload.start.y, 9, 0, Math.PI * 2); ctx.fill();

        // User trail
        if (points.length > 1) {
            ctx.strokeStyle = inGoal ? 'rgba(134, 239, 172, 0.95)' : 'rgba(252, 211, 77, 0.95)';
            ctx.lineWidth = 2.25; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
            ctx.beginPath(); ctx.moveTo(points[0].x, points[0].y);
            for (let i = 1; i < points.length; i++) ctx.lineTo(points[i].x, points[i].y);
            ctx.stroke();
        }

        // Moving target
        const [tx, ty] = curveAt(targetU);
        ctx.fillStyle = '#fbbf24';
        ctx.shadowColor = 'rgba(251, 191, 36, 0.6)';
        ctx.shadowBlur = 12;
        ctx.beginPath(); ctx.arc(tx, ty, 7, 0, Math.PI * 2); ctx.fill();
        ctx.shadowBlur = 0;
    }

    function pointerXY(e) {
        const rect = canvas.getBoundingClientRect();
        const sx = payload.canvas.w / rect.width;
        const sy = payload.canvas.h / rect.height;
        return { x: (e.clientX - rect.left) * sx, y: (e.clientY - rect.top) * sy };
    }

    canvas.addEventListener('pointerdown', (e) => {
        if (!payload) return;
        capturing = true;
        startedAt = performance.now();
        canvas.setPointerCapture(e.pointerId);
        const p = pointerXY(e);
        points.push({ x: p.x, y: p.y, t: 0, pressure: e.pressure || 0 });
        setStatus('capturing', 'capturing...');
        e.preventDefault();
    });
    canvas.addEventListener('pointermove', (e) => {
        if (!capturing) return;
        const p = pointerXY(e);
        points.push({ x: p.x, y: p.y, t: performance.now() - startedAt, pressure: e.pressure || 0 });
        // Live coaching: tell the user once they're in the goal zone so they
        // know they can release. This increases first-time success rate.
        if (payload && Math.hypot(p.x - payload.end.x, p.y - payload.end.y) <= 16) {
            setStatus('capturing', 'in goal · hold & release');
        }
    });
    function endStroke(e) {
        if (!capturing) return;
        capturing = false;
        try { canvas.releasePointerCapture(e.pointerId); } catch {}
        pointsEl.value = JSON.stringify(points);
        setStatus('ready', `${points.length} pts · ready`);
    }
    canvas.addEventListener('pointerup', endStroke);
    canvas.addEventListener('pointercancel', endStroke);

    resetBtn.addEventListener('click', () => issueChallenge());

    const errorBox = document.getElementById('signupError');
    const successPanel = document.getElementById('signupSuccess');
    const successEmail = document.getElementById('signupSuccessEmail');
    const successMessage = document.getElementById('signupSuccessMessage');

    function showError(text) {
        errorBox.textContent = text;
        errorBox.style.display = 'block';
        errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function clearError() {
        errorBox.textContent = '';
        errorBox.style.display = 'none';
    }
    function showSuccess(email, message) {
        successEmail.textContent = email;
        if (message) successMessage.textContent = message;
        form.style.display = 'none';
        clearError();
        successPanel.style.display = 'block';
        successPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearError();
        if (!challengeIdEl.value || !pointsEl.value) {
            setStatus('fail', 'trace the path first');
            showError('Please solve the captcha — drag the token along the path before submitting.');
            return;
        }
        submitBtn.disabled = true;
        const original = submitBtn.innerHTML;
        submitBtn.innerHTML = 'Submitting…';
        let data = null;
        try {
            const fd = new FormData(form);
            const r = await fetch(form.action, {
                method: 'POST',
                body: fd,
                headers: {
                    'X-SP-Fingerprint': fingerprint,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });
            try { data = await r.json(); } catch { data = null; }

            if (r.ok && data && data.status === 'ok') {
                setStatus('pass', 'verified · email sent');
                showSuccess(data.email, data.message);
                return;
            }

            // Validation errors come back as 422 with Laravel's error bag.
            let msg = (data && data.message)
                || (data && data.errors ? Object.values(data.errors).flat().join(' ') : '')
                || `Submission failed (HTTP ${r.status}).`;
            setStatus('fail', 'rejected — try again');
            showError(msg);
            // Refresh the captcha so the next attempt has a fresh challenge.
            await issueChallenge();
        } catch (err) {
            setStatus('fail', 'network error');
            showError('Network error — please try again.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = original;
        }
    });

    issueChallenge();
})();
</script>
@endpush
