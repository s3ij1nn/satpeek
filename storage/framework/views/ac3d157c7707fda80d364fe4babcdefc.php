<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    // Single source of truth — drives BOTH the form input names AND the
    // element ids. Pass `name="captcha"` for register/login (controllers
    // validate that exact field name); pass `name="ptc"` etc. for AJAX
    // submission paths that care only about reading the values back via JS.
    'name' => 'captcha',
    'height' => 200,
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    // Single source of truth — drives BOTH the form input names AND the
    // element ids. Pass `name="captcha"` for register/login (controllers
    // validate that exact field name); pass `name="ptc"` etc. for AJAX
    // submission paths that care only about reading the values back via JS.
    'name' => 'captcha',
    'height' => 200,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<?php
    // Sanitize: input names use underscore, element ids use hyphen.
    $base = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $name));
    $challengeIdName = $name . '_challenge_id';
    $pointsName = $name . '_points';
    $statusId = $base . '-status';
    $canvasId = $base . '-canvas';
    $resetId = $base . '-reset';
    $challengeIdInputId = $base . '-challenge-id';
    $pointsInputId = $base . '-points';
?>

<div class="captcha-block" data-trajectory-captcha
     data-status-id="<?php echo e($statusId); ?>"
     data-canvas-id="<?php echo e($canvasId); ?>"
     data-reset-id="<?php echo e($resetId); ?>"
     data-challenge-input-id="<?php echo e($challengeIdInputId); ?>"
     data-points-input-id="<?php echo e($pointsInputId); ?>">
    <div class="captcha-block__head">
        <span>Trajectory captcha</span>
        <span class="captcha-block__status" id="<?php echo e($statusId); ?>" data-state="ready">● ready</span>
    </div>
    <canvas id="<?php echo e($canvasId); ?>" width="320" height="<?php echo e((int) $height); ?>"
            aria-label="Drag from the green dot to the red goal following the moving target."></canvas>
    <div class="captcha-block__instr">
        <span>From the green dot, follow the yellow target. Release inside the red ring — it turns green when you're in.</span>
        <button type="button" class="captcha-block__reset" id="<?php echo e($resetId); ?>">↺ new</button>
    </div>
    <input type="hidden" name="<?php echo e($challengeIdName); ?>" id="<?php echo e($challengeIdInputId); ?>">
    <input type="hidden" name="<?php echo e($pointsName); ?>" id="<?php echo e($pointsInputId); ?>">
</div>

<?php if (! $__env->hasRenderedOnce('d68f7e8e-055a-4786-bc4f-19a65df8d0ab')): $__env->markAsRenderedOnce('d68f7e8e-055a-4786-bc4f-19a65df8d0ab'); ?>
<style>
    .captcha-block {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 0.6rem;
        padding: 1rem;
        min-width: 0;
        background: var(--bg-canvas, #07090f);
        border: 1px solid var(--border-strong, #2a3647);
        border-radius: var(--radius-md, 10px);
    }
    .captcha-block > * { min-width: 0; }
    .captcha-block__head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 0.75rem; min-height: 1.6rem;
        font-family: var(--font-mono, ui-monospace, monospace);
        font-size: 0.6875rem;
        color: var(--text-tertiary, #6b7686);
        text-transform: uppercase; letter-spacing: 0.14em;
    }
    .captcha-block__head > span:first-child { flex-shrink: 0; }
    .captcha-block__status {
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
        background: var(--bg-elev-2, #161d29);
        color: var(--text-tertiary, #6b7686);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        max-width: 65%; min-width: 6rem; text-align: center;
    }
    .captcha-block__status[data-state="ready"]     { background: rgba(103,232,249,0.12); color: #67e8f9; }
    .captcha-block__status[data-state="capturing"] { background: rgba(252,211,77,0.12);  color: #fcd34d; }
    .captcha-block__status[data-state="pass"]      { background: rgba(52,211,153,0.12);  color: #34d399; }
    .captcha-block__status[data-state="fail"]      { background: rgba(251,113,133,0.12); color: #fb7185; }
    .captcha-block canvas {
        display: block; width: 100%; max-width: 100%; height: auto;
        aspect-ratio: 320 / <?php echo e((int) $height); ?>;
        background: #060912;
        border-radius: 6px;
        cursor: crosshair;
        touch-action: none;
    }
    .captcha-block__instr {
        display: flex; justify-content: space-between; align-items: center;
        gap: 0.75rem; min-height: 1.4rem;
        font-family: var(--font-mono, ui-monospace, monospace);
        font-size: 0.6875rem; color: var(--text-tertiary, #6b7686);
    }
    .captcha-block__instr > span:first-child {
        flex: 1 1 auto; min-width: 0;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .captcha-block__reset {
        background: none; border: 0; color: inherit;
        font: inherit; cursor: pointer; text-decoration: underline;
    }
    .captcha-block__reset:hover { color: var(--text-secondary, #aab4c2); }
</style>

<script>
/**
 * Trajectory captcha — shared component bootstrapper.
 *
 * Auto-discovers any [data-trajectory-captcha] block on the page and wires up:
 *   - per-block /api/captcha/issue request with X-SP-Fingerprint header
 *   - canvas drawing of the moving target
 *   - pointer capture (mouse + touch + pen)
 *   - hidden inputs for form submission
 *
 * Forms that submit via fetch() must add the same X-SP-Fingerprint header so the
 * server-side ChallengeVerifier honours the issue-time fingerprint binding.
 * The page-level fingerprint stored in localStorage is exposed as
 * `window.SPCaptcha.fingerprint` for that purpose.
 */
(() => {
    const FP_KEY = 'satpeek_fingerprint';
    let fingerprint = localStorage.getItem(FP_KEY);
    if (!fingerprint) {
        fingerprint = (crypto.randomUUID && crypto.randomUUID()) ||
            (Date.now().toString(36) + Math.random().toString(36).slice(2));
        localStorage.setItem(FP_KEY, fingerprint);
    }
    window.SPCaptcha = window.SPCaptcha || {};
    window.SPCaptcha.fingerprint = fingerprint;

    function init(block) {
        const canvas = document.getElementById(block.dataset.canvasId);
        const status = document.getElementById(block.dataset.statusId);
        const resetBtn = document.getElementById(block.dataset.resetId);
        const challengeIdEl = document.getElementById(block.dataset.challengeInputId);
        const pointsEl = document.getElementById(block.dataset.pointsInputId);
        if (!canvas || !canvas.getContext) return;
        const ctx = canvas.getContext('2d');

        let payload = null, points = [], capturing = false, startedAt = 0, animId = null;

        function setStatus(state, text) {
            status.dataset.state = state;
            status.textContent = '● ' + text;
        }

        function fitCanvas() {
            if (!payload) return;
            const rect = canvas.getBoundingClientRect();
            const dpr = Math.max(1, window.devicePixelRatio || 1);
            canvas.width = Math.round(rect.width * dpr);
            canvas.height = Math.round(rect.height * dpr);
            ctx.setTransform(
                dpr * rect.width / payload.canvas.w, 0, 0,
                dpr * rect.height / payload.canvas.h, 0, 0
            );
        }
        window.addEventListener('resize', fitCanvas);

        function curveAt(u) {
            const p = payload;
            const x = p.start.x + (p.end.x - p.start.x) * u;
            const baseY = p.start.y + (p.end.y - p.start.y) * u;
            let y = baseY;
            if (p.curve === 'sine')      y = baseY + Math.sin(u * Math.PI * 2 * p.frequency) * p.amplitude;
            else if (p.curve === 'lissajous') y = baseY + Math.sin(u * Math.PI * 2 * p.frequency) * p.amplitude * Math.cos(u * Math.PI);
            return [x, y];
        }

        function startAnim() {
            const start = performance.now();
            if (animId) cancelAnimationFrame(animId);
            const tick = (now) => {
                if (!payload) return;
                const elapsed = capturing ? (now - startedAt) : ((now - start) % payload.durationMs);
                draw(Math.min(1, elapsed / payload.durationMs));
                animId = requestAnimationFrame(tick);
            };
            animId = requestAnimationFrame(tick);
        }

        function draw(targetU) {
            const W = payload.canvas.w, H = payload.canvas.h;
            ctx.clearRect(0, 0, W, H);
            ctx.fillStyle = '#060912'; ctx.fillRect(0, 0, W, H);
            ctx.strokeStyle = 'rgba(29, 38, 52, 0.55)'; ctx.lineWidth = 1;
            for (let x = 0; x < W; x += 24) { ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, H); ctx.stroke(); }
            for (let y = 0; y < H; y += 24) { ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke(); }
            ctx.strokeStyle = 'rgba(103, 232, 249, 0.15)'; ctx.lineWidth = 1.5;
            ctx.beginPath();
            for (let i = 0; i <= 80; i++) { const u = i / 80; const [x, y] = curveAt(u); if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y); }
            ctx.stroke();

            const last = points.length ? points[points.length - 1] : null;
            const inGoal = last && Math.hypot(last.x - payload.end.x, last.y - payload.end.y) <= 16;
            if (inGoal) { ctx.fillStyle = '#22c55e'; ctx.shadowColor = 'rgba(34,197,94,0.65)'; ctx.shadowBlur = 14; }
            else        { ctx.fillStyle = '#fb7185'; }
            ctx.beginPath(); ctx.arc(payload.end.x, payload.end.y, 14, 0, Math.PI * 2); ctx.fill();
            ctx.shadowBlur = 0;
            ctx.strokeStyle = inGoal ? 'rgba(34,197,94,0.8)' : 'rgba(251,113,133,0.55)';
            ctx.lineWidth = 1.5;
            ctx.beginPath(); ctx.arc(payload.end.x, payload.end.y, 22, 0, Math.PI * 2); ctx.stroke();

            ctx.fillStyle = '#22c55e';
            ctx.beginPath(); ctx.arc(payload.start.x, payload.start.y, 9, 0, Math.PI * 2); ctx.fill();

            if (points.length > 1) {
                ctx.strokeStyle = inGoal ? 'rgba(134,239,172,0.95)' : 'rgba(252,211,77,0.95)';
                ctx.lineWidth = 2.25; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
                ctx.beginPath(); ctx.moveTo(points[0].x, points[0].y);
                for (let i = 1; i < points.length; i++) ctx.lineTo(points[i].x, points[i].y);
                ctx.stroke();
            }

            const [tx, ty] = curveAt(targetU);
            ctx.fillStyle = '#fbbf24';
            ctx.shadowColor = 'rgba(251, 191, 36, 0.6)'; ctx.shadowBlur = 12;
            ctx.beginPath(); ctx.arc(tx, ty, 7, 0, Math.PI * 2); ctx.fill();
            ctx.shadowBlur = 0;
        }

        function pointerXY(e) {
            const rect = canvas.getBoundingClientRect();
            return {
                x: (e.clientX - rect.left) * payload.canvas.w / rect.width,
                y: (e.clientY - rect.top) * payload.canvas.h / rect.height,
            };
        }

        // Auto-refresh stale challenges so the 25 s solve window resets the
        // moment the user actually engages with the canvas. Without this, a
        // user who watches a 30 s ad before solving (or types a long form)
        // would always trip CAPTCHA_MAX_SOLVE_MS.
        const REFRESH_AFTER_MS = 15000;
        let issuedAtMs = 0;
        let refreshInFlight = false;

        async function refreshIfStale() {
            if (refreshInFlight) return;
            if (capturing) return;                       // never re-issue mid-drag
            if (!issuedAtMs || (Date.now() - issuedAtMs) < REFRESH_AFTER_MS) return;
            refreshInFlight = true;
            await issue();
            refreshInFlight = false;
        }
        canvas.addEventListener('pointerenter', refreshIfStale);
        canvas.addEventListener('focus', refreshIfStale);

        canvas.addEventListener('pointerdown', (e) => {
            if (!payload) return;
            capturing = true; startedAt = performance.now();
            lastSampleT = -Infinity;
            canvas.setPointerCapture(e.pointerId);
            const p = pointerXY(e);
            points.push({ x: p.x, y: p.y, t: 0, pressure: e.pressure || 0 });
            setStatus('capturing', 'capturing...');
            e.preventDefault();
        });
        // Throttle pointermove so high-DPI mice (500-1000 Hz polling) don't
        // accumulate thousands of points in one drag and trip max_points.
        // 8 ms = 125 Hz max sample rate — plenty for jitter / jerk analysis.
        const MIN_SAMPLE_DT_MS = 8;
        let lastSampleT = -Infinity;
        canvas.addEventListener('pointermove', (e) => {
            if (!capturing) return;
            const t = performance.now() - startedAt;
            if (t - lastSampleT < MIN_SAMPLE_DT_MS) return;
            lastSampleT = t;
            const p = pointerXY(e);
            points.push({ x: p.x, y: p.y, t, pressure: e.pressure || 0 });
            if (payload && Math.hypot(p.x - payload.end.x, p.y - payload.end.y) <= 16) {
                setStatus('capturing', 'in goal · hold & release');
            }
        });
        function endStroke(e) {
            if (!capturing) return;
            capturing = false;
            try { canvas.releasePointerCapture(e.pointerId); } catch {}
            pointsEl.value = JSON.stringify(points);
            setStatus('ready', points.length + ' pts · ready');
        }
        canvas.addEventListener('pointerup', endStroke);
        canvas.addEventListener('pointercancel', endStroke);

        async function issue() {
            points = [];
            challengeIdEl.value = '';
            pointsEl.value = '';
            setStatus('ready', 'fetching challenge...');
            try {
                const res = await fetch('/api/captcha/issue', {
                    headers: { 'Accept': 'application/json', 'X-SP-Fingerprint': fingerprint },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error('issue_failed:' + res.status);
                const ch = await res.json();
                payload = ch.payload;
                challengeIdEl.value = ch.challengeId;
                issuedAtMs = Date.now();
                fitCanvas();
                startAnim();
                setStatus('ready', 'drag from green dot');
            } catch (e) {
                setStatus('fail', 'captcha unavailable — refresh');
            }
        }

        resetBtn.addEventListener('click', () => issue());

        // Public JS API on the captcha block — preferred over querying hidden
        // inputs by name, which is fragile (input names depend on the `name`
        // attribute passed to the component and have caused bugs).
        block.spReset = issue;
        block.spGetState = () => ({
            challengeId: challengeIdEl.value,
            points: pointsEl.value,
            isReady: !!(challengeIdEl.value && pointsEl.value),
        });

        issue();
    }

    document.querySelectorAll('[data-trajectory-captcha]').forEach(init);
})();
</script>
<?php endif; ?>
<?php /**PATH /var/www/resources/views/components/trajectory-captcha.blade.php ENDPATH**/ ?>