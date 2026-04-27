<?php

namespace Tests\Feature\Shortlinks;

use App\Models\Shortlink;
use App\Models\User;
use App\Shortlinks\Providers\ShortenerClient;
use App\Shortlinks\Providers\ShortenerException;
use App\Shortlinks\ShortlinkProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the per-click rotation contract:
 *   - /api/shortlinks/{id}/start hands the viewer a freshly-issued shortened
 *     URL whenever provider_name + source_url are set
 *   - target_url + target_url_rotated_at get persisted so the admin panel
 *     reflects the latest rotation
 *   - shortener failures fall back to the previously-stored target_url with
 *     a log warning rather than 500ing the click
 *   - shortlinks with no provider_name keep the legacy static behaviour
 */
class RotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotation_replaces_target_url_with_freshly_shortened_value(): void
    {
        $user = User::factory()->create();
        $link = $this->seedRotatingLink([
            'source_url' => 'https://example.com/destination',
            'provider_name' => 'fake',
            'target_url' => 'https://provider.test/old',
        ]);

        $shortener = new SequenceFakeShortener('fake', [
            'https://provider.test/AAAAA',
            'https://provider.test/BBBBB',
        ]);
        $this->bindFakeProvider('fake', $shortener);

        $first = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start")->json();
        $this->assertSame('https://provider.test/AAAAA', $first['redirect_url']);
        $this->assertSame('https://provider.test/AAAAA', $link->fresh()->target_url);
        $this->assertNotNull($link->fresh()->target_url_rotated_at);

        // Second click → second URL. Same shortlink, different output — that's
        // the whole point of rotation.
        $second = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start")->json();
        $this->assertSame('https://provider.test/BBBBB', $second['redirect_url']);
        $this->assertSame('https://provider.test/BBBBB', $link->fresh()->target_url);

        // The two shorten() calls must have received DISTINCT input URLs —
        // the cache-buster is what stops btcut.io / friends from returning
        // the same slug for a repeat call.
        $this->assertCount(2, $shortener->received);
        $this->assertNotSame($shortener->received[0], $shortener->received[1]);
        // And the underlying canonical destination must still be present in
        // both — we're appending, not rewriting.
        foreach ($shortener->received as $u) {
            $this->assertStringStartsWith('https://example.com/destination', $u);
            $this->assertMatchesRegularExpression('/[?&]_r=[a-z0-9]+/', $u);
        }
    }

    public function test_rotation_failure_falls_back_to_stored_target_url(): void
    {
        $user = User::factory()->create();
        $link = $this->seedRotatingLink([
            'source_url' => 'https://example.com/destination',
            'provider_name' => 'fake',
            'target_url' => 'https://provider.test/cached-fallback',
        ]);
        $this->bindFakeProvider('fake', new ThrowingFakeShortener('fake'));

        $response = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start");
        $response->assertOk();
        $this->assertSame('https://provider.test/cached-fallback', $response->json('redirect_url'));
        // target_url must NOT be wiped out by the failure — keep serving stale.
        $this->assertSame('https://provider.test/cached-fallback', $link->fresh()->target_url);
    }

    public function test_static_shortlink_returns_target_url_without_rotation(): void
    {
        $user = User::factory()->create();
        $link = $this->seedRotatingLink([
            'source_url' => null,
            'provider_name' => null,
            'target_url' => 'https://example.com/static',
        ]);
        // Provider deliberately wired so a rogue rotation would explode the
        // test — assert by absence that we don't touch it.
        $this->bindFakeProvider('fake', new ThrowingFakeShortener('fake'));

        $response = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start");
        $response->assertOk();
        $this->assertSame('https://example.com/static', $response->json('redirect_url'));
        $this->assertNull($link->fresh()->target_url_rotated_at);
    }

    public function test_unconfigured_provider_falls_back_without_calling_shorten(): void
    {
        $user = User::factory()->create();
        $link = $this->seedRotatingLink([
            'source_url' => 'https://example.com/destination',
            'provider_name' => 'fake',
            'target_url' => 'https://provider.test/last-known-good',
        ]);
        // Provider exists but reports unconfigured — must not throw.
        $this->bindFakeProvider('fake', new UnconfiguredFakeShortener('fake'));

        $response = $this->actingAs($user)->postJson("/api/shortlinks/{$link->id}/start");
        $response->assertOk();
        $this->assertSame('https://provider.test/last-known-good', $response->json('redirect_url'));
    }

    private function seedRotatingLink(array $overrides): Shortlink
    {
        return Shortlink::create(array_merge([
            'source' => 'internal',
            'external_id' => 'rot-'.uniqid(),
            'title' => 'Rotation test',
            'target_url' => 'https://provider.test/seed',
            'reward_sat' => 5,
            'hold_seconds' => 5,
            'daily_limit_per_user' => 5,
            'is_active' => true,
        ], $overrides));
    }

    private function bindFakeProvider(string $name, ShortenerClient $client): void
    {
        $this->app->instance(
            ShortlinkProviderRegistry::class,
            new ShortlinkProviderRegistry([$name => $client]),
        );
    }
}

/**
 * Returns successive URLs from a queue. Asserts the rotator actually issues
 * fresh values on each call (vs. caching its own).
 */
class SequenceFakeShortener implements ShortenerClient
{
    /** @var array<int, string> URLs the rotator has handed us; for assertions. */
    public array $received = [];

    public function __construct(private string $name, private array $queue) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function shorten(string $url, ?string $alias = null): string
    {
        $this->received[] = $url;
        if (empty($this->queue)) {
            throw new ShortenerException('queue exhausted');
        }

        return array_shift($this->queue);
    }
}

class ThrowingFakeShortener implements ShortenerClient
{
    public function __construct(private string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function shorten(string $url, ?string $alias = null): string
    {
        throw new ShortenerException('boom');
    }
}

class UnconfiguredFakeShortener implements ShortenerClient
{
    public function __construct(private string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function shorten(string $url, ?string $alias = null): string
    {
        // Should never be reached because resolveRedirectUrl checks isConfigured() first.
        throw new ShortenerException('shorten() should not have been called');
    }
}
