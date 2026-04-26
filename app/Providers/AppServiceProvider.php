<?php

namespace App\Providers;

use App\BotDetection\ScoreEngine;
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
use App\Shortlinks\Providers\GenericShortenerClient;
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

        $this->app->singleton(ShortlinkProviderRegistry::class, function ($app) {
            $http = $app->make(HttpFactory::class);
            $clients = [];
            foreach ((array) config('satpeek.shortlink_providers', []) as $name => $cfg) {
                $clients[(string) $name] = new GenericShortenerClient(
                    http: $http,
                    name: (string) $name,
                    apiBase: (string) ($cfg['api_base'] ?? ''),
                    apiToken: (string) ($cfg['api_token'] ?? ''),
                );
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
            return new ScoreEngine([
                $app->make(ResponseTimeSignal::class),
                $app->make(TrajectoryEntropySignal::class),
                $app->make(FailureRateSignal::class),
                $app->make(FingerprintConsistencySignal::class),
                $app->make(TlsFingerprintSignal::class),
                $app->make(HeartbeatGapSignal::class),
                new IpReputationSignal($app->make(IpReputationProvider::class)),
            ]);
        });

        $this->app->bind(Signal::class, ResponseTimeSignal::class);
    }

    public function boot(): void {}
}
