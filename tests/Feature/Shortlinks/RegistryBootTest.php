<?php

namespace Tests\Feature\Shortlinks;

use App\Shortlinks\Providers\GenericShortenerClient;
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
        // each provider as a GenericShortenerClient. Tokens stay env-driven.
        $registry = $this->app->make(ShortlinkProviderRegistry::class);
        $names = array_keys($registry->all());

        // btcut + cuty + exe + shrtfly — bumping this list means updating the
        // .env.example + Filament action's allowlist of provider labels.
        $this->assertEqualsCanonicalizing(['btcut', 'cuty', 'exe', 'shrtfly'], $names);
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
