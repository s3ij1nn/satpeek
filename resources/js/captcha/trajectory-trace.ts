/**
 * SatPeek trajectory-trace captcha — client SDK.
 *
 * Renders a server-issued challenge on a <canvas>, captures pointer
 * trajectory (mouse / pen / touch) at native event rate, and submits
 * the full sample stream to /api/captcha/verify.
 *
 * Anti-2captcha properties:
 *  - The challenge state is a moving target — there is no single "answer"
 *    image to relay to a remote worker.
 *  - We submit (x, y, t, pressure) tuples; relay services cannot describe
 *    a continuous trajectory in their workflow.
 *  - The required dwell at the goal (200ms+) and the issued TTL window
 *    (default 800ms < solveMs < 25000ms) reject both scripted and human
 *    relay attempts.
 */

export type Point = {
    x: number;
    y: number;
    t: number;
    pressure: number;
};

export type ChallengePayload = {
    challengeId: string;
    provider: 'trajectory_trace';
    payload: {
        canvas: { w: number; h: number };
        curve: 'linear' | 'sine' | 'lissajous';
        start: { x: number; y: number };
        end: { x: number; y: number };
        amplitude: number;
        frequency: number;
        durationMs: number;
        instruction: string;
    };
    expiresAt: string;
    ttlMs: number;
};

export type FetchOptions = {
    issueUrl?: string;
    verifyUrl?: string;
    fingerprintHeader?: string;
    sessionHeader?: string;
    onSuccess?: (confidence: number) => void;
    onFailure?: (reason: string) => void;
};

const DEFAULTS: Required<FetchOptions> = {
    issueUrl: '/api/captcha/issue',
    verifyUrl: '/api/captcha/verify',
    fingerprintHeader: 'X-SP-Fingerprint',
    sessionHeader: 'X-SP-Session',
    onSuccess: () => {},
    onFailure: () => {},
};

export class TrajectoryCaptcha {
    private canvas: HTMLCanvasElement;
    private ctx: CanvasRenderingContext2D;
    private points: Point[] = [];
    private startedAt = 0;
    private animationId: number | null = null;
    private capturing = false;
    private challenge: ChallengePayload | null = null;
    private currentTargetX = 0;
    private currentTargetY = 0;
    private opts: Required<FetchOptions>;

    constructor(canvas: HTMLCanvasElement, opts: FetchOptions = {}) {
        this.canvas = canvas;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            throw new Error('canvas 2d context unavailable');
        }
        this.ctx = ctx;
        this.opts = { ...DEFAULTS, ...opts };
        this.bindEvents();
    }

    public async issue(fingerprint: string, sessionId: string): Promise<ChallengePayload> {
        const res = await fetch(this.opts.issueUrl, {
            headers: {
                'Accept': 'application/json',
                [this.opts.fingerprintHeader]: fingerprint,
                [this.opts.sessionHeader]: sessionId,
            },
            credentials: 'same-origin',
        });
        if (!res.ok) {
            throw new Error(`issue_failed:${res.status}`);
        }
        const data = (await res.json()) as ChallengePayload;
        this.challenge = data;
        this.canvas.width = data.payload.canvas.w;
        this.canvas.height = data.payload.canvas.h;
        this.points = [];
        this.startedAt = performance.now();
        this.draw();
        this.startAnimation();
        return data;
    }

    public async verify(fingerprint: string): Promise<{ passed: boolean; confidence: number; reason: string }> {
        if (!this.challenge) {
            throw new Error('no_active_challenge');
        }
        this.stopAnimation();
        const body = {
            challengeId: this.challenge.challengeId,
            points: this.points,
        };
        const res = await fetch(this.opts.verifyUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                [this.opts.fingerprintHeader]: fingerprint,
            },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (data.passed) {
            this.opts.onSuccess(data.confidence ?? 0);
        } else {
            this.opts.onFailure(data.reason ?? 'unknown');
        }
        return data;
    }

    public reset(): void {
        this.points = [];
        this.startedAt = 0;
        this.challenge = null;
        this.stopAnimation();
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
    }

    private bindEvents(): void {
        const onDown = (e: PointerEvent) => {
            if (!this.challenge) return;
            this.capturing = true;
            this.canvas.setPointerCapture(e.pointerId);
            this.recordPoint(e);
            e.preventDefault();
        };
        const onMove = (e: PointerEvent) => {
            if (!this.capturing) return;
            this.recordPoint(e);
            e.preventDefault();
        };
        const onUp = (e: PointerEvent) => {
            if (!this.capturing) return;
            this.recordPoint(e);
            this.capturing = false;
            try {
                this.canvas.releasePointerCapture(e.pointerId);
            } catch {
                // already released
            }
        };
        this.canvas.addEventListener('pointerdown', onDown);
        this.canvas.addEventListener('pointermove', onMove);
        this.canvas.addEventListener('pointerup', onUp);
        this.canvas.addEventListener('pointercancel', onUp);
    }

    private recordPoint(e: PointerEvent): void {
        const rect = this.canvas.getBoundingClientRect();
        const sx = this.canvas.width / rect.width;
        const sy = this.canvas.height / rect.height;
        const t = performance.now() - this.startedAt;
        this.points.push({
            x: (e.clientX - rect.left) * sx,
            y: (e.clientY - rect.top) * sy,
            t,
            pressure: typeof e.pressure === 'number' ? e.pressure : 0,
        });
    }

    private startAnimation(): void {
        if (!this.challenge) return;
        const start = performance.now();
        const tick = (now: number) => {
            if (!this.challenge) return;
            const u = Math.min(1, (now - start) / this.challenge.payload.durationMs);
            this.computeTarget(u);
            this.draw();
            if (u < 1) {
                this.animationId = requestAnimationFrame(tick);
            }
        };
        this.animationId = requestAnimationFrame(tick);
    }

    private stopAnimation(): void {
        if (this.animationId !== null) {
            cancelAnimationFrame(this.animationId);
            this.animationId = null;
        }
    }

    private computeTarget(u: number): void {
        if (!this.challenge) return;
        const p = this.challenge.payload;
        const x = p.start.x + (p.end.x - p.start.x) * u;
        const baseY = p.start.y + (p.end.y - p.start.y) * u;
        let y = baseY;
        if (p.curve === 'sine') {
            y = baseY + Math.sin(u * Math.PI * 2 * p.frequency) * p.amplitude;
        } else if (p.curve === 'lissajous') {
            y = baseY + Math.sin(u * Math.PI * 2 * p.frequency) * p.amplitude * Math.cos(u * Math.PI);
        }
        this.currentTargetX = x;
        this.currentTargetY = y;
    }

    private draw(): void {
        if (!this.challenge) return;
        const c = this.ctx;
        const w = this.canvas.width;
        const h = this.canvas.height;
        c.clearRect(0, 0, w, h);

        // Background grid for human visual orientation.
        c.fillStyle = '#0f172a';
        c.fillRect(0, 0, w, h);
        c.strokeStyle = '#1e293b';
        c.lineWidth = 1;
        for (let x = 0; x < w; x += 24) {
            c.beginPath();
            c.moveTo(x, 0);
            c.lineTo(x, h);
            c.stroke();
        }
        for (let y = 0; y < h; y += 24) {
            c.beginPath();
            c.moveTo(0, y);
            c.lineTo(w, y);
            c.stroke();
        }

        const p = this.challenge.payload;

        // Goal marker.
        c.fillStyle = '#ef4444';
        c.beginPath();
        c.arc(p.end.x, p.end.y, 14, 0, Math.PI * 2);
        c.fill();

        // Start marker.
        c.fillStyle = '#22c55e';
        c.beginPath();
        c.arc(p.start.x, p.start.y, 10, 0, Math.PI * 2);
        c.fill();

        // Trail of submitted points.
        if (this.points.length > 1) {
            c.strokeStyle = 'rgba(56, 189, 248, 0.85)';
            c.lineWidth = 2;
            c.beginPath();
            c.moveTo(this.points[0]!.x, this.points[0]!.y);
            for (let i = 1; i < this.points.length; i++) {
                const pt = this.points[i]!;
                c.lineTo(pt.x, pt.y);
            }
            c.stroke();
        }

        // Moving target.
        c.fillStyle = '#fbbf24';
        c.beginPath();
        c.arc(this.currentTargetX, this.currentTargetY, 8, 0, Math.PI * 2);
        c.fill();
    }
}
