<?php

namespace App\Providers;

use App\BotDetection\ScoreEngine;
use App\BotDetection\Signals\AsnStaticListSignal;
use App\BotDetection\Signals\FailureRateSignal;
use App\BotDetection\Signals\FingerprintConsistencySignal;
use App\BotDetection\Signals\HeartbeatGapSignal;
use App\BotDetection\Signals\IpReputationSignal;
use App\BotDetection\Signals\ResponseTimeSignal;
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
use App\IpReputation\Adapters\NullProvider;
use App\IpReputation\Adapters\ProxyCheckProvider;
use App\IpReputation\Contracts\IpReputationProvider;
use App\Offerwall\AdapterRegistry;
use App\Offerwall\BitcoTaskAdapter;
use App\Offerwall\MockAdapter;
use App\Models\ShortlinkProviderCredential;
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
        $this->app->singleton(Client::class, fn () => new Client());

        $this->app->singleton(CaptchaProvider::class, TrajectoryTraceProvider::class);
        $this->app->bind(ChallengeBuilder::class, fn ($app) => new ChallengeBuilder($app->make(CaptchaProvider::class)));
        $this->app->bind(ChallengeVerifier::class, fn ($app) => new ChallengeVerifier($app->make(CaptchaProvider::class)));

        $this->app->singleton(AdapterRegistry::class, function ($app) {
            $registry = new AdapterRegistry();
            $registry->register(new MockAdapter());
            $registry->register(new BitcoTaskAdapter($app->make(Client::class)));
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
            // null for every IP — gate / signal treat it as "no signal".
            $env = $app->environment();
            $disabled = (bool) ($cfg['disabled'] ?? false);
            if ($disabled || in_array($env, ['local', 'testing'], true)) {
                return new NullProvider();
            }

            $http = $app->make(Client::class);
            $providers = [];

            if (! empty($cfg['iphub']['api_key'])) {
                $providers[] = new IpHubProvider(
                    $http,
                    (string) $cfg['iphub']['api_key'],
                    (string) ($cfg['iphub']['api_base'] ?? 'https://v2.api.iphub.info'),
                );
            }
            if (true) {
                // ProxyCheck supports anonymous queries (lower quota) — register
                // even when no key is set. The provider sends an empty key.
                $providers[] = new ProxyCheckProvider(
                    $http,
                    (string) ($cfg['proxycheck']['api_key'] ?? ''),
                    (string) ($cfg['proxycheck']['api_base'] ?? 'https://proxycheck.io/v2'),
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
}
