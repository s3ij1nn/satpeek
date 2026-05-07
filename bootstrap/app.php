<?php

use App\Http\Middleware\AdblockGate;
use App\Http\Middleware\BotScoreGate;
use App\Http\Middleware\CloudflareClientIp;
use App\Http\Middleware\FingerprintRequired;
use App\Http\Middleware\IpBlocked;
use App\Http\Middleware\IpReputationGate;
use App\Http\Middleware\Ja4Capture;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // /up is registered manually in routes/web.php so we can return a
        // structured JSON health payload (db / redis / maxmind / providers)
        // instead of the framework's plain "OK". See HealthController.
        then: function () {
            Route::middleware(['web'])
                ->prefix('webhooks')
                ->name('webhooks.')
                ->group(__DIR__.'/../routes/webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // NOTE on TRUSTED_PROXIES: this bootstrap callback fires via
        // afterResolving(HttpKernel::class), which runs BEFORE the
        // LoadEnvironmentVariables bootstrapper in the HTTP request flow.
        // env('TRUSTED_PROXIES') returns null at this point even when the
        // value is set in .env, so we cannot configure trusted proxies
        // from here for web traffic. Instead the value is read via the
        // framework's built-in `config('trustedproxy.proxies')` fallback
        // path inside Illuminate\Http\Middleware\TrustProxies — see
        // config/trustedproxy.php for the env-driven binding.
        //
        // SECURITY: default in config/trustedproxy.php is `null` (trust
        // nothing). Setting `TRUSTED_PROXIES=*` accepts X-Forwarded-*
        // from ANY source — every BitcoTask-style IP-allowlisted webhook,
        // the IpReputationGate, SharedIpSignal, and rate-limit-per-IP
        // buckets all see the spoofed value. Use `*` only when an
        // upstream firewall already restricts inbound traffic to your
        // CDN / tunnel ranges; otherwise specify
        // `TRUSTED_PROXIES=<comma-separated CIDR list>` (e.g. Cloudflare's
        // published IP ranges). Local Docker without a proxy works fine
        // with the null default — Laravel reads REMOTE_ADDR directly and
        // never engages the trusted-proxy code path.

        // Normalise upstream JA4 TLS fingerprint headers (cf-ja4 / x-tls-ja4
        // / x-ja4 / x-sp-ja4) into the canonical X-SP-JA4 before any app code
        // reads it. Runs globally so admin / api / web requests all benefit
        // when the deployment sits behind a TLS-fingerprinting proxy.
        $middleware->prepend(Ja4Capture::class);

        // Operator-managed IP deny list. Hard 403 for any address listed
        // in `ip_block_entries`. Runs globally — landing page, /login,
        // /admin, every API endpoint — so the on-call response to an
        // active attack closes off ALL surfaces, not just the routes
        // already gated by IpReputationGate. Placed AFTER CloudflareClientIp
        // (prepend order is reversed) so request()->ip() returns the real
        // visitor before the block check, and BEFORE Ja4Capture so a
        // blocked attacker doesn't waste any further processing.
        $middleware->prepend(IpBlocked::class);

        // Resolve the real client IP from CF-Connecting-IP when behind
        // Cloudflare orange-cloud. Off by default (TRUST_CLOUDFLARE_PROXY
        // env), so dev / non-CF deployments behave unchanged. PREPENDED
        // LAST so it runs FIRST in the chain — every downstream middleware
        // (Ja4Capture / IpBlocked / IpReputationGate / BotScoreGate / etc)
        // and every controller call to request()->ip() sees the corrected
        // value.
        $middleware->prepend(CloudflareClientIp::class);

        // The Blade frontend is same-origin and uses session cookies, so the
        // /api routes need session start + CSRF + cookies. Stack the web
        // middleware group on top of the default api group.
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
        ]);

        $middleware->alias([
            'adblock.gate' => AdblockGate::class,
            'bot.gate' => BotScoreGate::class,
            'fingerprint' => FingerprintRequired::class,
            'ip.gate' => IpReputationGate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
