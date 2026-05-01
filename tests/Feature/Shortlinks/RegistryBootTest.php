<?php

namespace Tests\Feature\Shortlinks;

use App\Shortlinks\Providers\GenericShortenerClient;
use App\Shortlinks\Providers\OuoShortenerClient;
use App\Shortlinks\ShortlinkProviderRegistry;
use Tests\TestCase;

/**
 * Boots the AppServiceProvider with a synthetic `satpeek.shortlink_providers`
 * map and asserts the registry resolves what the operator configures. This is
 * the integration-level guarantee that a one-line config addition suffices to
 * register a new ouo-family shortener — the GenericShortenerClient unit tests
 * cover the wire shape, this one covers the wiring.
 */
class RegistryBootTest extends TestCase
{
    public function test_registry_exposes_only_providers_with_a_token(): void
    {
        config()->set('satpeek.shortlink_providers', [
            'btcut' => [
                'label' => 'btcut.io',
                'api_base' => 'https://btcut.io/api',
                'api_token' => 'btcut_token',
            ],
            'cuty' => [
                'label' => 'cuty.io',
                'api_base' => 'https://cuty.io/api',
                'api_token' => '', // unconfigured
            ],
            'exe' => [
                'label' => 'exe.io',
                'api_base' => 'https://exe.io/api',
                'api_token' => 'exe_token',
            ],
        ]);

        // Force the singleton to rebuild against the new config.
        $this->app->forgetInstance(ShortlinkProviderRegistry::class);
        $registry = $this->app->make(ShortlinkProviderRegistry::class);

        $this->assertEqualsCanonicalizing(['btcut', 'exe'], $registry->configuredNames());
        // get() must still resolve unconfigured providers — only configuredNames()
        // is filtered. The Filament action uses configuredNames() for visibility.
        $this->assertInstanceOf(GenericShortenerClient::class, $registry->get('cuty'));
    }

    public function test_default_config_registers_the_expected_provider_set(): void
    {
        // Ship-config sanity: the defaults in config/satpeek.php must register
        // each provider with the right transport class. Tokens stay env-driven.
        $registry = $this->app->make(ShortlinkProviderRegistry::class);
        $names = array_keys($registry->all());

        // Bumping this list means updating .env.example as well.
        $this->assertEqualsCanonicalizing(
            ['btcut', 'cuty', 'exe', 'shrtfly', 'ouo', 'earnow', 'shortano', 'shortino'],
            $names,
        );
    }

    public function test_transport_switch_picks_the_right_client_class(): void
    {
        config()->set('satpeek.shortlink_providers', [
            'btcut' => [
                'transport' => 'query',
                'api_base' => 'https://btcut.io/api',
                'api_token' => 'tk',
            ],
            'ouo' => [
                'transport' => 'path',
                'api_base' => 'https://ouo.io/api',
                'api_token' => 'tk',
            ],
            'fallback' => [
                // No transport key → defaults to query for backwards compat.
                'api_base' => 'https://fallback.example/api',
                'api_token' => 'tk',
            ],
        ]);
        $this->app->forgetInstance(ShortlinkProviderRegistry::class);
        $registry = $this->app->make(ShortlinkProviderRegistry::class);

        $this->assertInstanceOf(GenericShortenerClient::class, $registry->get('btcut'));
        $this->assertInstanceOf(OuoShortenerClient::class, $registry->get('ouo'));
        $this->assertInstanceOf(GenericShortenerClient::class, $registry->get('fallback'));
    }

    public function test_registry_handles_empty_provider_map(): void
    {
        config()->set('satpeek.shortlink_providers', []);
        $this->app->forgetInstance(ShortlinkProviderRegistry::class);

        $registry = $this->app->make(ShortlinkProviderRegistry::class);
        $this->assertSame([], $registry->all());
        $this->assertSame([], $registry->configuredNames());
    }
}
