/**
 * SatPeek PTC viewer — server-bound timer and heartbeat ping.
 *
 * The countdown is *server-driven*: client polls /api/ptc/<id>/heartbeat
 * with deliberate jitter, and the server tracks heartbeats received vs.
 * expected. Visibility-spoofing tricks (document.hidden override) cannot
 * make heartbeats appear server-side without making the requests, so the
 * test is whether the request actually arrives.
 */

export type ViewerInit = {
    viewId: number;
    epochToken: string;
    durationSec: number;
    onTick: (remaining: number) => void;
    onComplete: () => void;
    heartbeatUrl?: string;
    fingerprintHeader?: string;
    fingerprint: string;
};

export class PtcViewer {
    private remaining: number;
    private intervalId: number | null = null;
    private heartbeatId: number | null = null;
    private done = false;

    constructor(private readonly init: ViewerInit) {
        this.remaining = init.durationSec;
    }

    public start(): void {
        this.intervalId = window.setInterval(() => this.tick(), 1000);
        this.scheduleHeartbeat();
    }

    public stop(): void {
        if (this.intervalId) clearInterval(this.intervalId);
        if (this.heartbeatId) clearTimeout(this.heartbeatId);
        this.intervalId = null;
        this.heartbeatId = null;
    }

    private tick(): void {
        if (this.done) return;
        this.remaining = Math.max(0, this.remaining - 1);
        this.init.onTick(this.remaining);
        if (this.remaining === 0) {
            this.done = true;
            this.stop();
            this.init.onComplete();
        }
    }

    private scheduleHeartbeat(): void {
        if (this.done) return;
        // Jitter: 1500-2500 ms between heartbeats. Bots that poll on a
        // perfect interval flag HeartbeatGapSignal as "too uniform".
        const wait = 1500 + Math.floor(Math.random() * 1000);
        this.heartbeatId = window.setTimeout(async () => {
            await this.sendHeartbeat();
            this.scheduleHeartbeat();
        }, wait);
    }

    private async sendHeartbeat(): Promise<void> {
        try {
            await fetch(this.init.heartbeatUrl ?? `/api/ptc/${this.init.viewId}/heartbeat`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    [this.init.fingerprintHeader ?? 'X-SP-Fingerprint']: this.init.fingerprint,
                },
                body: JSON.stringify({
                    epoch_token: this.init.epochToken,
                    beacon_at_ms: Date.now(),
                }),
                credentials: 'same-origin',
            });
        } catch {
            // Ignore network blips — server tolerates a deficit margin.
        }
    }
}
