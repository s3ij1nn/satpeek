<div class="space-y-2" x-data="{
    fingerprint: localStorage.getItem('satpeek_fingerprint') || (() => {
        const v = (crypto.randomUUID && crypto.randomUUID()) || (Date.now().toString(36) + Math.random().toString(36).slice(2));
        localStorage.setItem('satpeek_fingerprint', v);
        return v;
    })(),
    statusText: 'fetching challenge...',
    statusState: 'ready',
    payload: null,
    points: [],
    capturing: false,
    startedAt: 0,
    lastSampleT: -Infinity,
    issuedAtMs: 0,
    animId: null,
    canvas: null,
    ctx: null,
    init() {
        this.canvas = this.$refs.canvas;
        this.ctx = this.canvas.getContext('2d');
        this.bindEvents();
        this.issue();
    },
    setStatus(state, text) { this.statusState = state; this.statusText = text; },
    async issue() {
        this.points = [];
        // Reset Livewire state so the next submit re-validates.
        try { this.$wire.set('captcha_challenge_id', '', false); } catch {}
        try { this.$wire.set('captcha_points', '', false); } catch {}
        this.setStatus('ready', 'fetching challenge...');
        try {
            const r = await fetch('/api/captcha/issue', {
                headers: { 'Accept': 'application/json', 'X-SP-Fingerprint': this.fingerprint },
                credentials: 'same-origin'
            });
            if (!r.ok) throw new Error('issue_failed:' + r.status);
            const ch = await r.json();
            this.payload = ch.payload;
            this.issuedAtMs = Date.now();
            // Stash the challenge id immediately — server has it persisted now.
            try { this.$wire.set('captcha_challenge_id', ch.challengeId, false); } catch {}
            this.fitCanvas();
            this.startAnim();
            this.setStatus('ready', 'drag from the green dot');
        } catch (e) {
            this.setStatus('fail', 'captcha unavailable — refresh the page');
        }
    },
    fitCanvas() {
        if (!this.payload) return;
        const rect = this.canvas.getBoundingClientRect();
        const dpr = Math.max(1, window.devicePixelRatio || 1);
        this.canvas.width = Math.round(rect.width * dpr);
        this.canvas.height = Math.round(rect.height * dpr);
        this.ctx.setTransform(
            dpr * rect.width / this.payload.canvas.w, 0, 0,
            dpr * rect.height / this.payload.canvas.h, 0, 0
        );
    },
    curveAt(u) {
        const p = this.payload;
        const x = p.start.x + (p.end.x - p.start.x) * u;
        const baseY = p.start.y + (p.end.y - p.start.y) * u;
        let y = baseY;
        if (p.curve === 'sine') y = baseY + Math.sin(u * Math.PI * 2 * p.frequency) * p.amplitude;
        else if (p.curve === 'lissajous') y = baseY + Math.sin(u * Math.PI * 2 * p.frequency) * p.amplitude * Math.cos(u * Math.PI);
        return [x, y];
    },
    startAnim() {
        const start = performance.now();
        if (this.animId) cancelAnimationFrame(this.animId);
        const tick = (now) => {
            if (!this.payload) return;
            const elapsed = this.capturing ? (now - this.startedAt) : ((now - start) % this.payload.durationMs);
            const u = Math.min(1, elapsed / this.payload.durationMs);
            this.draw(u);
            this.animId = requestAnimationFrame(tick);
        };
        this.animId = requestAnimationFrame(tick);
    },
    draw(targetU) {
        const p = this.payload, c = this.ctx;
        const W = p.canvas.w, H = p.canvas.h;
        c.clearRect(0, 0, W, H);
        c.fillStyle = '#060912'; c.fillRect(0, 0, W, H);
        c.strokeStyle = 'rgba(29, 38, 52, 0.55)'; c.lineWidth = 1;
        for (let x = 0; x < W; x += 24) { c.beginPath(); c.moveTo(x, 0); c.lineTo(x, H); c.stroke(); }
        for (let y = 0; y < H; y += 24) { c.beginPath(); c.moveTo(0, y); c.lineTo(W, y); c.stroke(); }
        c.strokeStyle = 'rgba(103, 232, 249, 0.15)'; c.lineWidth = 1.5; c.beginPath();
        for (let i = 0; i <= 80; i++) { const u = i/80; const [x,y] = this.curveAt(u); if (i===0) c.moveTo(x,y); else c.lineTo(x,y); }
        c.stroke();
        const last = this.points.length ? this.points[this.points.length - 1] : null;
        const inGoal = last && Math.hypot(last.x - p.end.x, last.y - p.end.y) <= 16;
        if (inGoal) {
            c.fillStyle = '#22c55e'; c.shadowColor = 'rgba(34,197,94,0.65)'; c.shadowBlur = 14;
        } else {
            c.fillStyle = '#fb7185';
        }
        c.beginPath(); c.arc(p.end.x, p.end.y, 14, 0, Math.PI*2); c.fill(); c.shadowBlur = 0;
        c.strokeStyle = inGoal ? 'rgba(34,197,94,0.8)' : 'rgba(251,113,133,0.55)';
        c.lineWidth = 1.5;
        c.beginPath(); c.arc(p.end.x, p.end.y, 22, 0, Math.PI*2); c.stroke();
        c.fillStyle = '#22c55e'; c.beginPath(); c.arc(p.start.x, p.start.y, 9, 0, Math.PI*2); c.fill();
        if (this.points.length > 1) {
            c.strokeStyle = inGoal ? 'rgba(134,239,172,0.95)' : 'rgba(252,211,77,0.95)';
            c.lineWidth = 2.25; c.lineCap = 'round'; c.lineJoin = 'round';
            c.beginPath(); c.moveTo(this.points[0].x, this.points[0].y);
            for (let i = 1; i < this.points.length; i++) c.lineTo(this.points[i].x, this.points[i].y);
            c.stroke();
        }
        const [tx, ty] = this.curveAt(targetU);
        c.fillStyle = '#fbbf24'; c.shadowColor = 'rgba(251, 191, 36, 0.6)'; c.shadowBlur = 12;
        c.beginPath(); c.arc(tx, ty, 7, 0, Math.PI*2); c.fill(); c.shadowBlur = 0;
    },
    pointerXY(e) {
        const rect = this.canvas.getBoundingClientRect();
        return { x: (e.clientX - rect.left) * this.payload.canvas.w / rect.width,
                 y: (e.clientY - rect.top) * this.payload.canvas.h / rect.height };
    },
    bindEvents() {
        // Auto-refresh stale challenges so the 25 s solve window starts when
        // the user actually engages the canvas, not when the page loaded.
        this.canvas.addEventListener('pointerenter', () => {
            if (this.capturing) return;
            if (!this.issuedAtMs || (Date.now() - this.issuedAtMs) < 15000) return;
            this.issue();
        });
        this.canvas.addEventListener('pointerdown', (e) => {
            if (!this.payload) return;
            this.capturing = true;
            this.startedAt = performance.now();
            this.lastSampleT = -Infinity;
            this.canvas.setPointerCapture(e.pointerId);
            const p = this.pointerXY(e);
            this.points.push({ x: p.x, y: p.y, t: 0, pressure: e.pressure || 0 });
            this.setStatus('capturing', 'capturing...');
            e.preventDefault();
        });
        this.canvas.addEventListener('pointermove', (e) => {
            if (!this.capturing) return;
            const t = performance.now() - this.startedAt;
            // Throttle to ~125 Hz so high-DPI mice can't blow past max_points.
            if (t - this.lastSampleT < 8) return;
            this.lastSampleT = t;
            const p = this.pointerXY(e);
            this.points.push({ x: p.x, y: p.y, t, pressure: e.pressure || 0 });
            if (this.payload && Math.hypot(p.x - this.payload.end.x, p.y - this.payload.end.y) <= 16) {
                this.setStatus('capturing', 'in goal · hold & release');
            }
        });
        const end = (e) => {
            if (!this.capturing) return;
            this.capturing = false;
            try { this.canvas.releasePointerCapture(e.pointerId); } catch {}
            // Push the trace into the Livewire component so authenticate() reads it.
            try { this.$wire.set('captcha_points', JSON.stringify(this.points), false); } catch {}
            this.setStatus('ready', this.points.length + ' pts · ready');
        };
        this.canvas.addEventListener('pointerup', end);
        this.canvas.addEventListener('pointercancel', end);
    }
}">
    <div style="display:flex; align-items:center; justify-content:space-between; gap: 0.75rem; min-height: 1.6rem; font-family: ui-monospace, monospace; font-size: 0.6875rem; color: #71717a; text-transform: uppercase; letter-spacing: 0.14em;">
        <span style="flex-shrink:0;">Trajectory captcha</span>
        <span x-text="'● ' + statusText"
              x-bind:style="({
                  ready: 'background: rgba(103,232,249,0.12); color: #67e8f9;',
                  capturing: 'background: rgba(252,211,77,0.12); color: #fcd34d;',
                  pass: 'background: rgba(52,211,153,0.12); color: #34d399;',
                  fail: 'background: rgba(251,113,133,0.12); color: #fb7185;'
              })[statusState] + ' padding: 2px 8px; border-radius: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 65%; min-width: 6rem; text-align: center;'"></span>
    </div>
    <canvas x-ref="canvas" width="320" height="200"
            style="display:block; width:100%; height:auto; aspect-ratio: 320 / 200; background: #060912; border-radius: 6px; border: 1px solid #1d2634; cursor: crosshair; touch-action: none;"
            aria-label="Drag from the green dot to the red goal following the moving target."></canvas>
    <div style="display:flex; justify-content: space-between; font-family: ui-monospace, monospace; font-size: 0.6875rem; color: #71717a;">
        <span>Drag the token along the path. Pause briefly at the red goal.</span>
        <button type="button" x-on:click="issue()"
                style="background: none; border: 0; color: #71717a; font: inherit; cursor: pointer; text-decoration: underline;">↺ new challenge</button>
    </div>
</div>
