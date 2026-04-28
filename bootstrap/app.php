<?php

use App\Http\Middleware\AdblockGate;
use App\Http\Middleware\BotScoreGate;
use App\Http\Middleware\CloudflareClientIp;
use App\Http\Middleware\FingerprintRequired;
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
        // Honour X-Forwarded-{Proto,Host,For,Port} when running behind a TLS-
        // terminating reverse proxy (Cloudflare orange-cloud, an ALB, ngrok in
        // local dev, etc). Without this, Laravel sees the proxy→app hop as
        // plain HTTP and route()/url() generate http:// links — those then
        // get blocked as mixed content when the page itself is loaded over
        // HTTPS through the proxy. `TRUSTED_PROXIES=*` is the right default
        // when the origin firewall already restricts inbound to your CDN /
        // tunnel; tighten to a CIDR list in stricter deployments.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // Normalise upstream JA4 TLS fingerprint headers (cf-ja4 / x-tls-ja4
        // / x-ja4 / x-sp-ja4) into the canonical X-SP-JA4 before any app code
        // reads it. Runs globally so admin / api / web requests all benefit
        // when the deployment sits behind a TLS-fingerprinting proxy.
        $middleware->prepend(Ja4Capture::class);

        // Resolve the real client IP from CF-Connecting-IP when behind
        // Cloudflare orange-cloud. Off by default (TRUST_CLOUDFLARE_PROXY
        // env), so dev / non-CF deployments behave unchanged. PREPENDED
        // LAST so it runs FIRST in the chain — every downstream middleware
        // (Ja4Capture / IpReputationGate / BotScoreGate / etc) and every
        // controller call to request()->ip() sees the corrected value.
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
