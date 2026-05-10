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
use App\Models\BotSignalWeight;
use App\Models\OfferwallProviderSetting;
use App\Models\ShortlinkProviderCredential;
use App\Offerwall\AdapterRegistry;
use App\Offerwall\BitcoTaskAdapter;
use App\Offerwall\MockAdapter;
use App\Payout\Btc\BtcHttpClient;
use App\Payout\Btc\BtcTxSigner;
use App\Payout\Btc\BtcUtxoSelector;
use App\Payout\Btc\BtcWalletBalanceMonitor;
use App\Payout\Eth\EthHttpClient;
use App\Payout\Eth\EthTxSigner;
use App\Payout\Eth\EthWalletBalanceMonitor;
use App\Payout\FaucetPayClient;
use App\Payout\Gateway\BtcOnchainGateway;
use App\Payout\Gateway\EthOnchainGateway;
use App\Payout\Gateway\FaucetPayGateway;
use App\Payout\Gateway\PayoutGatewayRegistry;
use App\Payout\Gateway\TronOnchainGateway;
use App\Payout\Gateway\TronUsdtTrc20Gateway;
use App\Payout\PayoutCurrencyRegistry;
use App\Payout\PriceOracle;
use App\Payout\Tron\TronHttpClient;
use App\Payout\Tron\TronTxSigner;
use App\Payout\Tron\TronUsdtWalletBalanceMonitor;
use App\Payout\Tron\TronWalletBalanceMonitor;
use App\Payout\WalletBalanceMonitorRegistry;
use App\Shortlinks\Providers\GenericShortenerClient;
use App\Shortlinks\Providers\OuoShortenerClient;
use App\Shortlinks\Providers\ShortenerClient;
use App\Shortlinks\ShortlinkProviderRegistry;
use GuzzleHttp\Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

        // Multi-currency payout — gateway registry is a singleton so
        // `ProcessWithdrawalJob` always sees the same set of registered
        // routes. Phase 1 wires only FaucetPay; per-chain onchain
        // gateways register here as they land in Phase 2+.
        $this->app->singleton(PayoutCurrencyRegistry::class);
        $this->app->singleton(PriceOracle::class, function ($app) {
            return new PriceOracle(
                $app->make(Client::class),
                $app->make(PayoutCurrencyRegistry::class),
            );
        });
        $this->app->singleton(TronTxSigner::class);
        $this->app->singleton(TronHttpClient::class, function ($app) {
            $tron = (array) config('satpeek.payout.onchain.tron', []);

            return new TronHttpClient(
                $app->make(Client::class),
                rpcUrls: (array) ($tron['rpc_urls'] ?? []),
                requestTimeoutSeconds: (int) ($tron['request_timeout_seconds'] ?? 10),
            );
        });
        $this->app->singleton(EthTxSigner::class);
        $this->app->singleton(EthHttpClient::class, function ($app) {
            $eth = (array) config('satpeek.payout.onchain.eth', []);

            return new EthHttpClient(
                $app->make(Client::class),
                rpcUrls: (array) ($eth['rpc_urls'] ?? ['https://ethereum-rpc.publicnode.com']),
                requestTimeoutSeconds: (int) ($eth['request_timeout_seconds'] ?? 10),
            );
        });
        $this->app->singleton(BtcTxSigner::class);
        $this->app->singleton(BtcUtxoSelector::class);
        $this->app->singleton(BtcHttpClient::class, function ($app) {
            $btc = (array) config('satpeek.payout.onchain.btc', []);

            return new BtcHttpClient(
                $app->make(Client::class),
                apiBases: (array) ($btc['api_bases'] ?? ['https://mempool.space/api']),
                requestTimeoutSeconds: (int) ($btc['request_timeout_seconds'] ?? 10),
            );
        });
        $this->app->singleton(PayoutGatewayRegistry::class, function ($app) {
            $registry = new PayoutGatewayRegistry;
            $registry->register(new FaucetPayGateway(
                $app->make(FaucetPayClient::class),
                $app->make(PayoutCurrencyRegistry::class),
            ));

            // Tron gateway is only registered when the operator has
            // explicitly opted in (TRON_ONCHAIN_ENABLED=true) AND the
            // hot-wallet env pair is present. Without both,
            // PayoutGatewayRegistry::has('onchain_trx') returns false →
            // WithdrawController removes 'onchain_trx' from
            // allowed-methods → users can't even submit a Tron
            // withdrawal. Defence-in-depth against a misconfigured
            // deploy that thinks it has the gateway when it doesn't.
            $tron = (array) config('satpeek.payout.onchain.tron', []);
            $tronEnabled = (bool) ($tron['enabled'] ?? false);
            $hotAddress = (string) ($tron['hot_wallet_address'] ?? '');
            $hotPriv = (string) ($tron['hot_wallet_private_key'] ?? '');
            if ($tronEnabled && $hotAddress !== '' && $hotPriv !== '') {
                $registry->register(new TronOnchainGateway(
                    $app->make(TronHttpClient::class),
                    $app->make(TronTxSigner::class),
                    hotWalletAddress: $hotAddress,
                    hotWalletPrivateKey: $hotPriv,
                ));

                // USDT-TRC20 piggybacks on the same hot wallet + RPC
                // surface; the only extra config is the per-network
                // contract address. Skip silently if the operator
                // hasn't supplied a contract address (e.g. a custom
                // private testnet that doesn't have USDT yet).
                $network = (string) ($tron['network'] ?? 'mainnet');
                $contracts = (array) ($tron['usdt_trc20_contract'] ?? []);
                $contract = (string) ($contracts[$network] ?? '');
                if ($contract !== '') {
                    $registry->register(new TronUsdtTrc20Gateway(
                        $app->make(TronHttpClient::class),
                        $app->make(TronTxSigner::class),
                        hotWalletAddress: $hotAddress,
                        hotWalletPrivateKey: $hotPriv,
                        contractAddress: $contract,
                    ));
                }
            }

            // ETH onchain — same conditional gating as Tron.
            $eth = (array) config('satpeek.payout.onchain.eth', []);
            $ethEnabled = (bool) ($eth['enabled'] ?? false);
            $ethAddress = (string) ($eth['hot_wallet_address'] ?? '');
            $ethPriv = (string) ($eth['hot_wallet_private_key'] ?? '');
            if ($ethEnabled && $ethAddress !== '' && $ethPriv !== '') {
                $registry->register(new EthOnchainGateway(
                    $app->make(EthHttpClient::class),
                    $app->make(EthTxSigner::class),
                    hotWalletAddress: $ethAddress,
                    hotWalletPrivateKey: $ethPriv,
                ));
            }

            // BTC onchain — same conditional gating as Tron / ETH.
            $btc = (array) config('satpeek.payout.onchain.btc', []);
            $btcEnabled = (bool) ($btc['enabled'] ?? false);
            $btcAddress = (string) ($btc['hot_wallet_address'] ?? '');
            $btcPriv = (string) ($btc['hot_wallet_private_key'] ?? '');
            if ($btcEnabled && $btcAddress !== '' && $btcPriv !== '') {
                $registry->register(new BtcOnchainGateway(
                    $app->make(BtcHttpClient::class),
                    $app->make(BtcTxSigner::class),
                    $app->make(BtcUtxoSelector::class),
                    hotWalletAddress: $btcAddress,
                    hotWalletPrivateKey: $btcPriv,
                ));
            }

            return $registry;
        });

        // Wallet balance monitors are populated alongside their
        // gateway. Empty when Tron isn't enabled — dashboard widget
        // renders no rows in that case.
        $this->app->singleton(WalletBalanceMonitorRegistry::class, function ($app) {
            $registry = new WalletBalanceMonitorRegistry;
            $tron = (array) config('satpeek.payout.onchain.tron', []);
            $tronEnabled = (bool) ($tron['enabled'] ?? false);
            $hotAddress = (string) ($tron['hot_wallet_address'] ?? '');
            if ($tronEnabled && $hotAddress !== '') {
                $registry->register(new TronWalletBalanceMonitor(
                    $app->make(TronHttpClient::class),
                    $hotAddress,
                ));
                $network = (string) ($tron['network'] ?? 'mainnet');
                $contracts = (array) ($tron['usdt_trc20_contract'] ?? []);
                $contract = (string) ($contracts[$network] ?? '');
                if ($contract !== '') {
                    $registry->register(new TronUsdtWalletBalanceMonitor(
                        $app->make(TronHttpClient::class),
                        $hotAddress,
                        $contract,
                    ));
                }
            }
            // ETH wallet monitor — same gating as the gateway.
            $eth = (array) config('satpeek.payout.onchain.eth', []);
            $ethEnabled = (bool) ($eth['enabled'] ?? false);
            $ethAddress = (string) ($eth['hot_wallet_address'] ?? '');
            if ($ethEnabled && $ethAddress !== '') {
                $registry->register(new EthWalletBalanceMonitor(
                    $app->make(EthHttpClient::class),
                    $ethAddress,
                ));
            }
            // BTC wallet monitor — same gating as the gateway.
            $btc = (array) config('satpeek.payout.onchain.btc', []);
            $btcEnabled = (bool) ($btc['enabled'] ?? false);
            $btcAddress = (string) ($btc['hot_wallet_address'] ?? '');
            if ($btcEnabled && $btcAddress !== '') {
                $registry->register(new BtcWalletBalanceMonitor(
                    $app->make(BtcHttpClient::class),
                    $btcAddress,
                ));
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        self::applyBotSignalWeightOverrides();
        $this->registerRateLimiters();
    }

    /**
     * Merge `BotSignalWeight` DB rows over the env-driven defaults and
     * write the result back to `config('satpeek.bot_score.weights')`
     * so the rest of the app keeps reading a single source.
     *
     * - DB row `is_enabled = false` → weight forced to 0 (signal still
     *   evaluated for transparency in the BotScore.signals JSON, but
     *   contributes nothing to the composite score).
     * - Schema-missing environments (early-boot console, fresh test DB
     *   before migrations) silently degrade to the config defaults,
     *   mirroring the offerwall + shortlink-credential resolvers.
     */
    private static function applyBotSignalWeightOverrides(): void
    {
        // Snapshot the FILE defaults BEFORE we overwrite them so the
        // operator-facing Filament resource can show "default" vs
        // "current override" side-by-side. Without this snapshot the
        // BotSignalWeightResource's "Default" column would shadow the
        // first-saved override and confuse the operator next time
        // they view the row.
        $defaults = (array) config('satpeek.bot_score.weights', []);
        config()->set('satpeek.bot_score.default_weights', $defaults);

        try {
            $rows = BotSignalWeight::all();
        } catch (\Throwable $e) {
            // Schema-missing is the legit pre-migration case (artisan
            // migrate, fresh test DB before RefreshDatabase has run)
            // and not worth logging — every test-suite run would emit
            // noise. ANY OTHER error (PDO connect issue, container
            // resolution error) is interesting because it silently
            // regresses every override the operator has saved.
            $msg = $e->getMessage();
            $isSchemaMissing = str_contains($msg, 'no such table')           // sqlite
                || str_contains($msg, 'does not exist')                       // pgsql
                || str_contains($msg, "doesn't exist");                       // mysql
            if (! $isSchemaMissing) {
                Log::warning(
                    'BotSignalWeight boot override skipped: '.$msg
                );
            }

            return;
        }

        $weights = $defaults;
        foreach ($rows as $row) {
            $name = (string) $row->name;
            if (! (bool) $row->is_enabled) {
                $weights[$name] = 0.0;

                continue;
            }
            $weights[$name] = (float) $row->weight;
        }
        config()->set('satpeek.bot_score.weights', $weights);
    }

    /**
     * Named rate limiters for the API surface. Anonymous endpoints are
     * keyed by IP; authenticated ones prefer the user ID so a user
     * behind a shared NAT isn't punished by neighbours' traffic.
     *
     * Limits err on the lenient side — they're a DoS / abuse backstop,
     * not the primary gate. Captcha + bot-score + adblock checks remain
     * the main throttles on bot behaviour. The numbers below assume the
     * default `bot_score.min_reevaluate_interval_seconds = 300` keeps
     * captcha refreshes and per-action verifies bounded for legit users.
     */
    private function registerRateLimiters(): void
    {
        // Captcha issue is hit by EVERY page render that includes the
        // widget (login, register, /shortlinks/auth, /ptc/auth,
        // /read-articles/internal). 60/min/IP comfortably covers a
        // legit user opening multiple tabs while still defeating a
        // CDP-driven scraper trying to harvest seeds.
        RateLimiter::for('captcha-issue', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));

        // Captcha verify cost = one DB read + shape/jerk math. Cheap
        // server-side but expensive in challenge-id consumption budget
        // for a bot. 30/min/IP rules out a relay grinding through
        // pre-issued challenges without blocking a multi-tab user.
        RateLimiter::for('captcha-verify', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));

        // Beacons land on every page load with `mouse|focus|key|fp`
        // payloads. Generous because the legitimate volume is
        // page-load-driven, not user-action-driven. Tight enough to
        // catch a script firing 1000s/sec of fake telemetry.
        RateLimiter::for('beacon', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));

        // Earning starts (PTC view / shortlink click / internal article
        // read) cost a row insert + an external HTTP for shortlinks. 30/min
        // is well above any human cadence (a chip click takes 5-10 s of
        // shortener interstitial; 30/min = 2/s would mean opening a chip
        // every half-second for a full minute).
        RateLimiter::for('earning-start', function (Request $r) {
            $key = optional($r->user())->id ?: $r->ip();

            return Limit::perMinute(30)->by('earning-start:'.$key);
        });

        // Withdrawals are heavy (FaucetPay round-trip, balance ledger
        // write, possible review-queue routing). A legit user submits
        // 1-2/day; 5/min/user is a hard ceiling against a script
        // hammering /withdraw to cycle balance + auth state.
        RateLimiter::for('withdraw', function (Request $r) {
            $key = optional($r->user())->id ?: $r->ip();

            return Limit::perMinute(5)->by('withdraw:'.$key);
        });

        // Adblock report fires on EVERY authenticated page load + on
        // any change to detection state. 30/min/user is generous for
        // multi-tab users while catching a script faking "cleared"
        // reports faster than a real browser would.
        RateLimiter::for('adblock-report', function (Request $r) {
            $key = optional($r->user())->id ?: $r->ip();

            return Limit::perMinute(30)->by('adblock-report:'.$key);
        });

        // Email verification resend keys on the AUTHENTICATED user,
        // not the IP — the attacker has the session by definition
        // (you can't resend without being logged in), so an IP-keyed
        // throttle invites botnet bypass. 1/min + 6/hour gives a
        // legit user space to retry after a typo or slow inbox while
        // ruling out the inbox-bombing pattern (bot floods
        // /email/verification-notification to spam the target's
        // mailbox). The captcha gate on the same endpoint is
        // defence-in-depth; this limiter is the primary guard.
        RateLimiter::for('verification-send', function (Request $r) {
            $key = optional($r->user())->id ?: $r->ip();

            return [
                Limit::perMinute(1)->by('verification-send:min:'.$key),
                Limit::perHour(6)->by('verification-send:hr:'.$key),
            ];
        });
    }

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
