<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // /up is registered manually in routes/web.php so we can return a
        // structured JSON health payload (db / redis / maxmind / providers)
        // instead of the framework's plain "OK". See HealthController.
        then: function () {
            Illuminate\Support\Facades\Route::middleware(['web'])
                ->prefix('webhooks')
                ->name('webhooks.')
                ->group(__DIR__.'/../routes/webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Normalise upstream JA4 TLS fingerprint headers (cf-ja4 / x-tls-ja4
        // / x-ja4 / x-sp-ja4) into the canonical X-SP-JA4 before any app code
        // reads it. Runs globally so admin / api / web requests all benefit
        // when the deployment sits behind a TLS-fingerprinting proxy.
        $middleware->prepend(\App\Http\Middleware\Ja4Capture::class);

        // The Blade frontend is same-origin and uses session cookies, so the
        // /api routes need session start + CSRF + cookies. Stack the web
        // middleware group on top of the default api group.
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);

        $middleware->alias([
            'bot.gate' => \App\Http\Middleware\BotScoreGate::class,
            'fingerprint' => \App\Http\Middleware\FingerprintRequired::class,
            'ip.gate' => \App\Http\Middleware\IpReputationGate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
