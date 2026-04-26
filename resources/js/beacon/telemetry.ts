/**
 * SatPeek behavioural telemetry beacon.
 *
 * Streams batched mouse / focus / keystroke / fingerprint events to
 * /api/beacon every ~2-3 seconds. Powers BotDetection signals.
 */

type EventKind = 'mouse' | 'focus' | 'key' | 'heartbeat' | 'fp';

type BeaconEvent = {
    kind: EventKind;
    payload: Record<string, unknown>;
    observed_at_ms: number;
};

export class TelemetryBeacon {
    private buffer: BeaconEvent[] = [];
    private flushTimer: number | null = null;
    private readonly flushIntervalMs = 2500;
    private readonly maxBufferSize = 150;

    constructor(
        private readonly url: string = '/api/beacon',
        private readonly fingerprintHeader = 'X-SP-Fingerprint',
        private readonly sessionHeader = 'X-SP-Session',
        private readonly fingerprint = '',
        private readonly sessionId = '',
    ) {
        this.bindCollectors();
        this.startFlushLoop();
    }

    public recordFingerprint(hash: string, components: Record<string, unknown>): void {
        this.push({ kind: 'fp', payload: { hash, components }, observed_at_ms: Date.now() });
    }

    public recordHeartbeat(view: { viewId: number; epochToken: string }): void {
        this.push({ kind: 'heartbeat', payload: view, observed_at_ms: Date.now() });
    }

    private push(ev: BeaconEvent): void {
        this.buffer.push(ev);
        if (this.buffer.length >= this.maxBufferSize) {
            void this.flush();
        }
    }

    private bindCollectors(): void {
        let lastMouse = 0;
        window.addEventListener('mousemove', (e) => {
            const now = Date.now();
            if (now - lastMouse < 80) return;
            lastMouse = now;
            this.push({
                kind: 'mouse',
                payload: { x: e.clientX, y: e.clientY, mv: e.movementX, mvy: e.movementY },
                observed_at_ms: now,
            });
        });
        window.addEventListener('focus', () => {
            this.push({ kind: 'focus', payload: { state: 'in' }, observed_at_ms: Date.now() });
        });
        window.addEventListener('blur', () => {
            this.push({ kind: 'focus', payload: { state: 'out' }, observed_at_ms: Date.now() });
        });
        window.addEventListener('keydown', (e) => {
            this.push({
                kind: 'key',
                payload: { code: e.code, repeat: e.repeat },
                observed_at_ms: Date.now(),
            });
        });
    }

    private startFlushLoop(): void {
        this.flushTimer = window.setInterval(() => {
            void this.flush();
        }, this.flushIntervalMs);
    }

    private async flush(): Promise<void> {
        if (this.buffer.length === 0) return;
        const events = this.buffer.splice(0, this.buffer.length);
        try {
            await fetch(this.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    [this.fingerprintHeader]: this.fingerprint,
                    [this.sessionHeader]: this.sessionId,
                },
                body: JSON.stringify({ events }),
                credentials: 'same-origin',
                keepalive: true,
            });
        } catch {
            // Drop on error — telemetry must never break the main flow.
        }
    }
}
