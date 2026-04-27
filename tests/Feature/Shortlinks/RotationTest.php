<?php

namespace Tests\Feature\Shortlinks;

use App\Models\Shortlink;
use App\Models\ShortlinkClick;
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

    public function test_each_sl_redirect_mints_a_freshly_shortened_url(): void
    {
        // Rotation now happens at the /sl/{token} server-side redirector,
        // not on /start (which only returns the SatPeek redirector path).
        // Each follow of /sl/{token} hits the shortener with a cache-busted
        // source so two clicks never see the same shortened slug.
        $user = User::factory()->create();
        $link = $this->seedRotatingLink([
            'source_url' => 'https://example.com/destination',
            'provider_name' => 'fake',
            'target_url' => 'https://provider.test/seed',
        ]);
        $shortener = new SequenceFakeShortener('fake', [
            'https://provider.test/AAAAA',
            'https://provider.test/BBBBB',
        ]);
        $this->bindFakeProvider('fake', $shortener);

        $first = $this->startClick($user, $link);
        $second = $this->startClick($user, $link);

        // First /sl/{token} → 302 to AAAAA, target_url updated.
        $this->actingAs($user)->get(route('shortlinks.click', ['token' => $first]))
            ->assertRedirect('https://provider.test/AAAAA');
        $this->assertSame('https://provider.test/AAAAA', $link->fresh()->target_url);
        $this->assertNotNull($link->fresh()->target_url_rotated_at);

        // Second /sl/{token} → 302 to BBBBB.
        $this->actingAs($user)->get(route('shortlinks.click', ['token' => $second]))
            ->assertRedirect('https://provider.test/BBBBB');
        $this->assertSame('https://provider.test/BBBBB', $link->fresh()->target_url);

        // Each shorten() received a DISTINCT input URL thanks to the
        // cache-buster — that's what stops btcut.io / friends from
        // returning the same slug.
        $this->assertCount(2, $shortener->received);
        $this->assertNotSame($shortener->received[0], $shortener->received[1]);
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

        $token = $this->startClick($user, $link);

        // Shortener throws → 302 to the cached target_url so the click
        // isn't wasted, target_url isn't wiped out.
        $this->actingAs($user)->get(route('shortlinks.click', ['token' => $token]))
            ->assertRedirect('https://provider.test/cached-fallback');
        $this->assertSame('https://provider.test/cached-fallback', $link->fresh()->target_url);
    }

    // NOTE: there used to be a `test_static_shortlink_returns_target_url_without_rotation`
    // here that exercised non-rotating shortlinks. Removed because operator
    // policy now forbids static shortlinks on /shortlinks (see
    // ShortlinkController::servableQuery + the Filament form which makes
    // both source_url and provider_name required). BitcoTask offerwall
    // entries cover the "no internal rotation" case.

    public function test_unconfigured_provider_falls_back_without_calling_shorten(): void
    {
        $user = User::factory()->create();
        $link = $this->seedRotatingLink([
            'source_url' => 'https://example.com/destination',
            'provider_name' => 'fake',
            'target_url' => 'https://provider.test/last-known-good',
        ]);
        $this->bindFakeProvider('fake', new UnconfiguredFakeShortener('fake'));

        $token = $this->startClick($user, $link);

        $this->actingAs($user)->get(route('shortlinks.click', ['token' => $token]))
            ->assertRedirect('https://provider.test/last-known-good');
    }

    public function test_sl_redirector_404s_for_other_users_token(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $link = $this->seedRotatingLink(['target_url' => 'https://example.com/x']);
        $token = $this->startClick($owner, $link);

        $this->actingAs($stranger)
            ->get(route('shortlinks.click', ['token' => $token]))
            ->assertNotFound();
    }

    public function test_sl_redirector_410s_for_already_resolved_click(): void
    {
        $user = User::factory()->create();
        $link = $this->seedRotatingLink(['target_url' => 'https://example.com/x']);
        $token = $this->startClick($user, $link);
        ShortlinkClick::where('epoch_token', $token)->update(['status' => 'verified']);

        $this->actingAs($user)
            ->get(route('shortlinks.click', ['token' => $token]))
            ->assertStatus(410);
    }

    private function startClick(User $user, Shortlink $link): string
    {
        return $this->actingAs($user)
            ->postJson("/api/shortlinks/{$link->id}/start")
            ->json('epoch_token');
    }

    private function seedRotatingLink(array $overrides): Shortlink
    {
        return Shortlink::create(array_merge([
            'source' => 'internal',
            'external_id' => 'rot-'.uniqid(),
            'title' => 'Rotation test',
            'target_url' => 'https://provider.test/seed',
            'source_url' => 'https://destination.example.com/source',
            'provider_name' => 'mock',
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
