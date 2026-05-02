<?php

namespace App\Providers;

use App\BotDetection\PolicyEnforcer;
use App\BotDetection\ScoreEngine;
use App\BotDetection\Signals\AsnStaticListSignal;
use App\BotDetection\Signals\FailureRateSignal;
use App\BotDetection\Signals\FingerprintConsistencySignal;
use App\BotDetection\Signals\HeartbeatGapSignal;
use App\BotDetection\Signals\IpReputationSignal;
use App\BotDetection\Signals\PayoutBurstSignal;
use App\BotDetection\Signals\RegistrationBurstSignal;
use App\BotDetection\Signals\ResponseTimeSignal;
use App\BotDetection\Signals\SharedIpSignal;
use App\BotDetection\Signals\Signal;
use App\BotDetection\Signals\TlsFingerprintSignal;
use App\BotDetection\Signals\TrajectoryEntropySignal;
use App\Captcha\ChallengeBuilder;
use App\Captcha\ChallengeVerifier;
use App\Captcha\Contracts\CaptchaProvider;
use App\Captcha\TrajectoryTraceProvider;
use App\IpReputation\Adapters\CachedProvider;
use App\IpReputation\Adapters\CompositeProvider;
use App\IpReputation\Adapters\IpHubProvider;
use App\IpReputation\Adapters\MaxMindAsnProvider;
use App\IpReputation\Adapters\NullProvider;
use App\IpReputation\Adapters\ProxyCheckProvider;
use App\IpReputation\Contracts\IpReputationProvider;
use App\Models\OfferwallProviderSetting;
use App\Models\ShortlinkProviderCredential;
use App\Offerwall\AdapterRegistry;
use App\Offerwall\BitcoTaskAdapter;
use App\Offerwall\MockAdapter;
use App\Shortlinks\Providers\GenericShortenerClient;
use App\Shortlinks\Providers\OuoShortenerClient;
use App\Shortlinks\Providers\ShortenerClient;
use App\Shortlinks\ShortlinkProviderRegistry;
use GuzzleHttp\Client;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, fn () => new Client);

        $this->app->singleton(CaptchaProvider::class, TrajectoryTraceProvider::class);
        $this->app->bind(ChallengeBuilder::class, fn ($app) => new ChallengeBuilder(
            $app->make(CaptchaProvider::class),
            $app->make(PolicyEnforcer::class),
        ));
        $this->app->bind(ChallengeVerifier::class, fn ($app) => new ChallengeVerifier($app->make(CaptchaProvider::class)));

        // Per-request scope (not singleton) so admin toggles in the Filament
        // OfferwallProviderSetting resource take effect immediately without a
        // queue restart. The merge step pushes any DB-managed enable flags
        // into config('satpeek.offerwalls.enabled') BEFORE the registry's
        // enabled() method reads from there, so AdapterRegistry stays a
        // pure consumer of config.
        $this->app->scoped(AdapterRegistry::class, function ($app) {
            self::applyOfferwallDbOverrides();

            $registry = new AdapterRegistry;
            $registry->register(new MockAdapter);
            $registry->register(new BitcoTaskAdapter($app->make(HttpFactory::class)));

            return $registry;
        });

        // Per-request scope (not singleton) so admin updates to credentials in
        // the Filament UI take effect immediately without an app restart. The
        // resolver merges config defaults (transport, base URL, label) with
        // the operator's DB credential row (api_token, optional overrides).
        // Schema-missing environments (early-boot console, fresh test DB)
        // fall back to the env/config token alone — no migration ordering trap.
        $this->app->scoped(ShortlinkProviderRegistry::class, function ($app) {
            $http = $app->make(HttpFactory::class);
            $clients = [];

            $dbRows = self::loadCredentialRows();

            foreach ((array) config('satpeek.shortlink_providers', []) as $name => $cfg) {
                $name = (string) $name;
                $cfg = (array) $cfg;
                $row = $dbRows[$name] ?? null;
                if ($row !== null) {
                    if (! ((bool) $row->is_active)) {
                        continue; // operator explicitly disabled this provider
                    }
                    $cfg['transport'] = $row->transport ?: ($cfg['transport'] ?? 'query');
                    $cfg['api_base'] = $row->api_base ?: ($cfg['api_base'] ?? '');
                    $cfg['api_token'] = $row->api_token ?: ($cfg['api_token'] ?? '');
                }
                $clients[$name] = self::buildShortenerClient($http, $name, $cfg);
            }

            return new ShortlinkProviderRegistry($clients);
        });

        $this->app->singleton(IpReputationProvider::class, function ($app) {
            $cfg = config('satpeek.ip_reputation');

            // Disable remote reputation lookups in local/testing environments
            // or when explicitly turned off via env. The Null provider returns
            // null for every IP — gate / signal treat it as "no signal". The
            // MaxMind ASN provider is local, however, so we still register it
            // when a database file is configured even in local dev.
            $env = $app->environment();
            $disabled = (bool) ($cfg['disabled'] ?? false);
            if ($disabled || in_array($env, ['local', 'testing'], true)) {
                $local = self::buildLocalOnlyProvider($cfg);

                return $local ?? new NullProvider;
            }

            $http = $app->make(Client::class);
            $providers = [];

            // Local providers first — sub-millisecond, no network, no quota.
            if ($maxmind = self::buildMaxMindProvider($cfg)) {
                $providers[] = $maxmind;
            }

            // ProxyCheck before IPHub: ProxyCheck has stronger detection
            // coverage (catches ISP/residential proxies, datacenter fronting,
            // VPN exit nodes that IPHub misses) and supports anonymous
            // queries on a lower quota when no key is configured. IPHub
            // stays as a fallback for IPs ProxyCheck returns no verdict on.
            $providers[] = new ProxyCheckProvider(
                $http,
                (string) ($cfg['proxycheck']['api_key'] ?? ''),
                (string) ($cfg['proxycheck']['api_base'] ?? 'https://proxycheck.io/v2'),
            );

            if (! empty($cfg['iphub']['api_key'])) {
                $providers[] = new IpHubProvider(
                    $http,
                    (string) $cfg['iphub']['api_key'],
                    (string) ($cfg['iphub']['api_base'] ?? 'https://v2.api.iphub.info'),
                );
            }

            $composite = new CompositeProvider($providers);

            return new CachedProvider(
                $composite,
                (int) ($cfg['cache_ttl'] ?? 86400),
                (int) ($cfg['cache_negative_ttl'] ?? 600),
            );
        });

        $this->app->singleton(ScoreEngine::class, function ($app) {
            $reputation = $app->make(IpReputationProvider::class);

            return new ScoreEngine([
                $app->make(ResponseTimeSignal::class),
                $app->make(TrajectoryEntropySignal::class),
                $app->make(FailureRateSignal::class),
                $app->make(FingerprintConsistencySignal::class),
                $app->make(TlsFingerprintSignal::class),
                $app->make(HeartbeatGapSignal::class),
                new IpReputationSignal($reputation),
                new AsnStaticListSignal($reputation),
                new SharedIpSignal,
                new RegistrationBurstSignal,
                new PayoutBurstSignal,
            ]);
        });

        $this->app->bind(Signal::class, ResponseTimeSignal::class);
    }

    public function boot(): void {}

    private static function buildShortenerClient(HttpFactory $http, string $name, array $cfg): ShortenerClient
    {
        $apiBase = (string) ($cfg['api_base'] ?? '');
        $apiToken = (string) ($cfg['api_token'] ?? '');
        $transport = (string) ($cfg['transport'] ?? 'query');

        return match ($transport) {
            'path' => new OuoShortenerClient($http, $name, $apiBase, $apiToken),
            default => new GenericShortenerClient($http, $name, $apiBase, $apiToken),
        };
    }

    private static function buildMaxMindProvider(array $cfg): ?MaxMindAsnProvider
    {
        $path = (string) ($cfg['maxmind']['asn_db'] ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        return new MaxMindAsnProvider($path);
    }

    /**
     * Returns a local-only IpReputationProvider for the local/testing
     * environments. Right now MaxMind is the only offline source we wrap.
     * Returns null when nothing local is configured (caller falls back to
     * NullProvider).
     */
    private static function buildLocalOnlyProvider(array $cfg): ?IpReputationProvider
    {
        if ($maxmind = self::buildMaxMindProvider($cfg)) {
            return new CachedProvider(
                new CompositeProvider([$maxmind]),
                (int) ($cfg['cache_ttl'] ?? 86400),
                (int) ($cfg['cache_negative_ttl'] ?? 600),
            );
        }

        return null;
    }

    /**
     * @return array<string, ShortlinkProviderCredential>
     */
    private static function loadCredentialRows(): array
    {
        try {
            return ShortlinkProviderCredential::all()->keyBy('name')->all();
        } catch (\Throwable $e) {
            // Boot path before the migration has run (artisan migrate, fresh
            // test DB before a feature test setup, etc.) — silently degrade
            // to the env/config defaults so the rest of the app still boots.
            return [];
        }
    }

    /**
     * Merge `OfferwallProviderSetting` DB rows over the env-driven enabled
     * list and write the result back to runtime config so the rest of the
     * app keeps reading a single source. DB precedence:
     *
     *   - `is_enabled = true`  → adapter is included even if env list omits it
     *   - `is_enabled = false` → adapter is excluded even if env list contains it
     *
     * Schema-missing environments (early-boot console, fresh test DB before
     * migrations) silently degrade to the env list alone, mirroring the
     * shortlink-credential resolver above.
     */
    private static function applyOfferwallDbOverrides(): void
    {
        try {
            $rows = OfferwallProviderSetting::all();
        } catch (\Throwable) {
            return;
        }

        $enabled = (array) config('satpeek.offerwalls.enabled', []);
        $enabled = array_values(array_filter(array_map('strval', $enabled), fn (string $n): bool => $n !== ''));

        foreach ($rows as $row) {
            $name = (string) $row->name;
            if ((bool) $row->is_enabled) {
                if (! in_array($name, $enabled, true)) {
                    $enabled[] = $name;
                }
            } else {
                $enabled = array_values(array_diff($enabled, [$name]));
            }
        }

        config()->set('satpeek.offerwalls.enabled', $enabled);
    }
}
