<?php

namespace Tests\Feature\Shortlinks;

use App\Models\ShortlinkProviderCredential;
use App\Shortlinks\Providers\GenericShortenerClient;
use App\Shortlinks\Providers\OuoShortenerClient;
use App\Shortlinks\ShortlinkProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The runtime registry should prefer admin-stored credentials over the
 * env-driven defaults so the operator can rotate keys from Filament without
 * a redeploy. These tests pin that contract end-to-end.
 */
class CredentialOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_token_overrides_env_token(): void
    {
        config()->set('satpeek.shortlink_providers.btcut', [
            'label' => 'btcut.io',
            'transport' => 'query',
            'api_base' => 'https://btcut.io/api',
            'api_token' => 'env_default_token',
        ]);
        ShortlinkProviderCredential::create([
            'name' => 'btcut',
            'transport' => 'query',
            'api_base' => 'https://btcut.io/api',
            'api_token' => 'admin_set_token',
            'is_active' => true,
        ]);

        // Refresh the scoped binding so the new row is observed.
        $this->app->forgetInstance(ShortlinkProviderRegistry::class);
        $client = $this->app->make(ShortlinkProviderRegistry::class)->get('btcut');

        // Reflection: peek at the private apiToken to confirm the override.
        $r = new \ReflectionProperty(GenericShortenerClient::class, 'apiToken');
        $this->assertSame('admin_set_token', $r->getValue($client));
    }

    public function test_env_token_used_when_no_db_row_exists(): void
    {
        config()->set('satpeek.shortlink_providers.btcut', [
            'label' => 'btcut.io',
            'transport' => 'query',
            'api_base' => 'https://btcut.io/api',
            'api_token' => 'env_only_token',
        ]);
        $this->assertSame(0, ShortlinkProviderCredential::where('name', 'btcut')->count());

        $this->app->forgetInstance(ShortlinkProviderRegistry::class);
        $client = $this->app->make(ShortlinkProviderRegistry::class)->get('btcut');

        $r = new \ReflectionProperty(GenericShortenerClient::class, 'apiToken');
        $this->assertSame('env_only_token', $r->getValue($client));
    }

    public function test_inactive_db_row_removes_provider_from_registry(): void
    {
        config()->set('satpeek.shortlink_providers.btcut', [
            'label' => 'btcut.io',
            'transport' => 'query',
            'api_base' => 'https://btcut.io/api',
            'api_token' => 'env_default_token',
        ]);
        ShortlinkProviderCredential::create([
            'name' => 'btcut',
            'transport' => 'query',
            'api_base' => 'https://btcut.io/api',
            'api_token' => 'admin_set_token',
            'is_active' => false,
        ]);

        $this->app->forgetInstance(ShortlinkProviderRegistry::class);
        $registry = $this->app->make(ShortlinkProviderRegistry::class);

        $this->assertArrayNotHasKey('btcut', $registry->all());
    }

    public function test_db_transport_override_picks_correct_client_class(): void
    {
        // The provider key is 'btcut' (config defaults to query transport),
        // but the operator can override transport to 'path' via the DB row
        // — the registry must respect that and instantiate OuoShortenerClient.
        config()->set('satpeek.shortlink_providers.btcut', [
            'transport' => 'query',
            'api_base' => 'https://btcut.io/api',
            'api_token' => 'env_default',
        ]);
        ShortlinkProviderCredential::create([
            'name' => 'btcut',
            'transport' => 'path',
            'api_base' => 'https://something.example/api',
            'api_token' => 'tk',
            'is_active' => true,
        ]);

        $this->app->forgetInstance(ShortlinkProviderRegistry::class);
        $client = $this->app->make(ShortlinkProviderRegistry::class)->get('btcut');

        $this->assertInstanceOf(OuoShortenerClient::class, $client);
    }

    public function test_api_token_is_encrypted_at_rest(): void
    {
        ShortlinkProviderCredential::create([
            'name' => 'btcut',
            'transport' => 'query',
            'api_base' => 'https://btcut.io/api',
            'api_token' => 'plaintext_token_42',
            'is_active' => true,
        ]);

        // Round-trip via Eloquent decrypts as expected.
        $this->assertSame('plaintext_token_42', ShortlinkProviderCredential::first()->api_token);

        // Raw row in storage must NOT contain the plaintext token.
        $raw = (string) DB::table('shortlink_provider_credentials')->first()->api_token;
        $this->assertNotSame('plaintext_token_42', $raw);
        $this->assertStringNotContainsString('plaintext_token_42', $raw);
    }
}
