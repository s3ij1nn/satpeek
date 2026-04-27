<?php

declare(strict_types=1);

namespace Tests\Feature\Shortlinks;

use App\Http\Controllers\Api\ShortlinkController;
use App\Models\Shortlink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the operator-policy filter "only rotation-enabled shortlinks are
 * served". Static shortlinks (no provider_name / no source_url) used to be
 * surfaced; they're not anymore. BitcoTask offers cover the no-internal-
 * rotation case via OfferwallMerge, not this table.
 *
 * Pinned at three layers (servableQuery + index API + start API) so a
 * future refactor that re-introduces the static path silently fails CI.
 */
class ServableFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_servable_query_excludes_static_shortlinks(): void
    {
        $rotating = $this->seedLink([
            'source_url' => 'https://example.com/dest',
            'provider_name' => 'mock',
        ]);
        $static = $this->seedLink([
            'source_url' => null,
            'provider_name' => null,
        ]);

        $ids = ShortlinkController::servableQuery()->pluck('id')->all();

        $this->assertContains($rotating->id, $ids);
        $this->assertNotContains($static->id, $ids);
    }

    public function test_servable_query_excludes_inactive_rotating_links(): void
    {
        $inactive = $this->seedLink([
            'source_url' => 'https://example.com/dest',
            'provider_name' => 'mock',
            'is_active' => false,
        ]);

        $ids = ShortlinkController::servableQuery()->pluck('id')->all();

        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_index_api_does_not_return_static_shortlinks(): void
    {
        $user = User::factory()->create();
        $rotating = $this->seedLink([
            'title' => 'rotating-link',
            'source_url' => 'https://example.com/dest',
            'provider_name' => 'mock',
        ]);
        $this->seedLink([
            'title' => 'static-link-should-be-hidden',
            'source_url' => null,
            'provider_name' => null,
        ]);

        $response = $this->actingAs($user)->getJson('/api/shortlinks');

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('rotating-link', $titles);
        $this->assertNotContains('static-link-should-be-hidden', $titles);
    }

    public function test_start_api_404s_on_static_shortlink_id(): void
    {
        $user = User::factory()->create();
        $static = $this->seedLink([
            'source_url' => null,
            'provider_name' => null,
        ]);

        $response = $this->actingAs($user)->postJson("/api/shortlinks/{$static->id}/start");

        // 404 (findOrFail through servableQuery) — operator policy:
        // static rows aren't reachable from the user-facing API even
        // when an attacker enumerates the numeric id directly.
        $response->assertStatus(404);
    }

    private function seedLink(array $overrides = []): Shortlink
    {
        return Shortlink::create(array_merge([
            'source' => 'internal',
            'external_id' => 'sf-'.uniqid(),
            'title' => 'Servable filter test',
            'target_url' => 'https://provider.test/seed',
            'reward_sat' => 5,
            'hold_seconds' => 5,
            'daily_limit_per_user' => 5,
            'is_active' => true,
        ], $overrides));
    }
}
