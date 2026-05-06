<?php

declare(strict_types=1);
use Illuminate\Http\Middleware\TrustProxies;

/**
 * Trusted-proxy configuration consumed by the framework's built-in
 * {@see TrustProxies} via its legacy
 * `config('trustedproxy.proxies')` fallback path.
 *
 * Why a config file rather than the bootstrap/app.php callback path?
 * The bootstrap `withMiddleware()` callback is registered via
 * `afterResolving(HttpKernel::class)`, which fires BEFORE the
 * LoadEnvironmentVariables bootstrapper runs in the HTTP request flow.
 * env('TRUSTED_PROXIES') returns null at that point even with a populated
 * .env, so the static `TrustProxies::at()` call there is a silent no-op
 * for web traffic (it works only in CLI, where env is loaded earlier).
 *
 * Config files are loaded by LoadConfiguration AFTER LoadEnvironmentVariables,
 * so env() works here. The framework's TrustProxies::setTrustedProxyIpAddresses()
 * picks the value up via its `?: config('trustedproxy.proxies')` fallback and
 * the request scheme is correctly resolved as https when the proxy sends
 * X-Forwarded-Proto: https.
 *
 * SECURITY: default is `null` (trust nothing). Setting `TRUSTED_PROXIES=*`
 * accepts X-Forwarded-* from ANY source — every BitcoTask-style IP-
 * allowlisted webhook, the IpReputationGate, SharedIpSignal, and
 * rate-limit-per-IP buckets all see the spoofed value. Use `*` only
 * when an upstream firewall already restricts inbound traffic to your
 * CDN / tunnel ranges; otherwise specify
 * `TRUSTED_PROXIES=<comma-separated CIDR list>` (e.g. Cloudflare's
 * published IP ranges).
 */
return [
    'proxies' => env('TRUSTED_PROXIES'),
];
