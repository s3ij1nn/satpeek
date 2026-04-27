<?php

declare(strict_types=1);

namespace Tests\Feature\Offerwall;

use App\Models\OfferwallProviderSetting;
use App\Models\User;
use App\Offerwall\AdapterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the DB-overrides-env contract for the offerwall enable flag, so an
 * operator can flip `bitcotask` on the moment the publisher review approves
 * API access without a redeploy.
 *
 *   - DB row with `is_enabled=true` makes the adapter participate even
 *     when `OFFERWALLS_ENABLED` is empty (the default state until env is
 *     edited).
 *   - DB row with `is_enabled=false` excludes the adapter even when env
 *     lists it (an emergency disable lever).
 *   - Filament resource is admin-only.
 */
class OfferwallProviderSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_row_enabled_adds_adapter_even_when_env_empty(): void
    {
        config()->set('satpeek.offerwalls.enabled', []);
        OfferwallProviderSetting::create(['name' => 'bitcotask', 'is_enabled' => true]);

        $registry = $this->app->make(AdapterRegistry::class);
        $names = array_map(fn ($a) => $a->name(), $registry->enabled());

        $this->assertContains('bitcotask', $names);
    }

    public function test_db_row_disabled_overrides_env_inclusion(): void
    {
        config()->set('satpeek.offerwalls.enabled', ['bitcotask']);
        OfferwallProviderSetting::create(['name' => 'bitcotask', 'is_enabled' => false]);

        $registry = $this->app->make(AdapterRegistry::class);
        $names = array_map(fn ($a) => $a->name(), $registry->enabled());

        $this->assertNotContains('bitcotask', $names);
    }

    public function test_no_db_row_falls_back_to_env_only(): void
    {
        config()->set('satpeek.offerwalls.enabled', ['bitcotask']);

        $registry = $this->app->make(AdapterRegistry::class);
        $names = array_map(fn ($a) => $a->name(), $registry->enabled());

        $this->assertContains('bitcotask', $names);
    }

    public function test_default_state_returns_no_offerwalls(): void
    {
        // Default ship config: env empty, no DB rows. The platform stays on
        // internal inventory only — important because the publisher review
        // hasn't approved API access yet.
        config()->set('satpeek.offerwalls.enabled', []);

        $registry = $this->app->make(AdapterRegistry::class);

        $this->assertSame([], $registry->enabled());
    }

    public function test_filament_resource_blocks_non_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/admin/offerwall-provider-settings');

        // Filament redirects unauthorised users to /admin/login.
        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
    }

    public function test_filament_resource_allows_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        OfferwallProviderSetting::create(['name' => 'bitcotask', 'is_enabled' => true, 'notes' => 'review pending']);

        $response = $this->actingAs($admin)->get('/admin/offerwall-provider-settings');

        $response->assertOk();
        $response->assertSee('bitcotask', false);
    }
}
