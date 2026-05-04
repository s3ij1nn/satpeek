<?php

declare(strict_types=1);

namespace Tests\Feature\Shortlinks;

use App\Captcha\TrajectoryTraceProvider;
use App\Models\CaptchaChallenge;
use App\Models\ShortlinkClick;
use App\Models\ShortlinkProviderCredential;
use App\Models\User;
use App\Shortlinks\Providers\ShortenerClient;
use App\Shortlinks\ShortlinkProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the per-click /shortlinks/auth/{token} landing contract:
 *   - URL slug rotates every click (each /start mints a fresh 28-char token)
 *   - the page is owner-scoped (cross-user requests 404, no info leak)
 *   - already-resolved clicks return 410 (single-use, no replay)
 *   - the token-keyed completion endpoint behaves like the legacy
 *     numeric-clickId one but resolves by epoch_token
 */
class AuthLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeProvider('mock', new FixedShortener('mock', 'https://mock.test/AAAAAA'));
    }

    public function test_each_click_yields_a_unique_auth_url_token(): void
    {
        $user = User::factory()->create();
        $this->seedProvider();

        $first = $this->actingAs($user)->postJson('/api/shortlinks/start/mock')->json();
        $second = $this->actingAs($user)->postJson('/api/shortlinks/start/mock')->json();

        $this->assertNotSame($first['epoch_token'], $second['epoch_token']);
        $this->assertMatchesRegularExpression('/^sc_[a-z0-9]{28}$/', $first['epoch_token']);
        $this->assertMatchesRegularExpression('/^sc_[a-z0-9]{28}$/', $second['epoch_token']);
    }

    public function test_landing_page_renders_for_owning_user_with_pending_click(): void
    {
        $user = User::factory()->create();
        $click = $this->seedClick(['user_id' => $user->id, 'status' => 'pending']);

        $response = $this->actingAs($user)->get(route('shortlinks.auth', ['token' => $click->epoch_token]));

        $response->assertOk();
        $response->assertSee($click->epoch_token, false);
    }

    public function test_landing_page_does_not_render_post_return_hold_countdown_ui(): void
    {
        // v0.6.0 regression lock: the previous design rendered a
        // hold-after-return countdown timer that ran on the auth page
        // BEFORE the user was even confirmed to have traversed the
        // shortener. We removed both the UI and the corresponding JS to
        // close the loophole where a user could close the shortener tab
        // and still walk through the timer. This pins the absence so a
        // future "let's bring back the countdown for UX warmth" PR fails
        // CI loud and clear.
        $user = User::factory()->create();
        $click = $this->seedClick(['user_id' => $user->id, 'status' => 'pending']);

        $response = $this->actingAs($user)->get(route('shortlinks.auth', ['token' => $click->epoch_token]));

        $response->assertOk();
        $response->assertDontSee('Time remaining', false);
        $response->assertDontSee('countdown__num', false);
        $response->assertDontSee('id="slLeft"', false);
        $response->assertDontSee('tickInit', false);
    }

    public function test_landing_page_renders_captcha_unlocked_from_first_paint(): void
    {
        // Companion to the above: the captcha widget must be present and
        // the claim button must NOT carry the `disabled` attribute on
        // initial render. The v0.5.x design `disabled`'d the button
        // until the (now-deleted) hold timer expired; v0.6.0 ships it
        // unlocked because the shortener interstitial IS the wait.
        $user = User::factory()->create();
        $click = $this->seedClick(['user_id' => $user->id, 'status' => 'pending']);

        $response = $this->actingAs($user)->get(route('shortlinks.auth', ['token' => $click->epoch_token]));

        $response->assertOk();
        $response->assertSee('data-trajectory-captcha', false);
        $response->assertSee('id="slClaim"', false);
        $response->assertDontSee('id="slClaim" disabled', false);
    }

    public function test_landing_page_404s_for_other_users_token(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $click = $this->seedClick(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->get(route('shortlinks.auth', ['token' => $click->epoch_token]))
            ->assertNotFound();
    }

    public function test_landing_page_410s_for_already_resolved_click(): void
    {
        $user = User::factory()->create();
        $click = $this->seedClick([
            'user_id' => $user->id,
            'status' => 'verified',
            'completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('shortlinks.auth', ['token' => $click->epoch_token]))
            ->assertStatus(410);
    }

    public function test_landing_page_404s_for_unknown_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('shortlinks.auth', ['token' => 'sc_zzzzzzzzzzzzzzzzzzzzzzzzzzzz']))
            ->assertNotFound();
    }

    public function test_token_endpoint_credits_balance_on_success(): void
    {
        $user = User::factory()->create(['balance_sat' => 0, 'total_earned_sat' => 0]);
        $click = $this->seedClick([
            'user_id' => $user->id,
            'reward_sat' => 9,
            'hold_seconds' => 5,
            'started_at' => Carbon::now()->subSeconds(7),
        ]);

        $challenge = $this->seedChallenge($user);
        $challenge->update(['status' => 'verified']);

        $response = $this->actingAs($user)->postJson(
            "/api/shortlinks/auth/{$click->epoch_token}/complete",
            ['epoch_token' => $click->epoch_token, 'captcha_challenge_id' => $challenge->challenge_id],
        );

        $response->assertOk()->assertJson(['ok' => true, 'reward_sat' => 9]);
        $this->assertSame(9, (int) $user->fresh()->balance_sat);
        $this->assertSame('verified', ShortlinkClick::find($click->id)->status);
    }

    public function test_token_endpoint_404s_for_other_users_token(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $click = $this->seedClick(['user_id' => $owner->id]);
        // Bind to the stranger so they CAN consume their own captcha —
        // the rejection here is from the click's owner-scoped lookup,
        // not the captcha consumer.
        $challenge = $this->seedChallenge($stranger);
        $challenge->update(['status' => 'verified']);

        $this->actingAs($stranger)->postJson(
            "/api/shortlinks/auth/{$click->epoch_token}/complete",
            ['epoch_token' => $click->epoch_token, 'captcha_challenge_id' => $challenge->challenge_id],
        )->assertStatus(404)->assertJson(['error' => 'click_not_found']);
    }

    private function seedProvider(array $overrides = []): ShortlinkProviderCredential
    {
        return ShortlinkProviderCredential::create(array_merge([
            'name' => 'mock',
            'label' => 'Mock provider',
            'transport' => 'query',
            'api_base' => 'https://mock.test/api',
            'api_token' => 'mock_token',
            'is_active' => true,
            'reward_sat' => 5,
            'hold_seconds' => 5,
            'daily_limit_per_user' => 5,
        ], $overrides));
    }

    private function seedClick(array $overrides): ShortlinkClick
    {
        $defaults = [
            'provider_name' => 'mock',
            'reward_sat' => 5,
            'hold_seconds' => 5,
            'epoch_token' => 'sc_'.bin2hex(random_bytes(14)),
            'status' => 'pending',
            'started_at' => Carbon::now(),
        ];

        return ShortlinkClick::create(array_merge($defaults, $overrides));
    }

    private function bindFakeProvider(string $name, ShortenerClient $client): void
    {
        $this->app->instance(
            ShortlinkProviderRegistry::class,
            new ShortlinkProviderRegistry([$name => $client]),
        );
    }

    private function seedChallenge(?User $user = null): CaptchaChallenge
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $issuedAt = Carbon::now()->subSeconds(3);

        return CaptchaChallenge::create([
            'challenge_id' => 'cc_test_'.uniqid(),
            'user_id' => $user?->id,
            'session_id' => 'test',
            'provider' => 'trajectory_trace',
            'seed' => 'test-seed',
            'expected_shape' => $shape,
            'fingerprint_hash' => null,
            'client_ip' => '127.0.0.1',
            'ja4' => null,
            'user_agent' => 'phpunit',
            'status' => 'issued',
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->copy()->addSeconds(60),
        ]);
    }
}

class FixedShortener implements ShortenerClient
{
    public function __construct(private string $name, private string $url) {}

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
        return $this->url;
    }
}
