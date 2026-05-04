<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Captcha\TrajectoryTraceProvider;
use App\Models\CaptchaChallenge;
use App\Models\InternalArticle;
use App\Models\InternalArticleView;
use App\Models\PtcAd;
use App\Models\PtcView;
use App\Models\ShortlinkClick;
use App\Models\ShortlinkProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regression lock against the bypass found in the post-v0.7.0
 * security review: the earn-complete endpoints used to validate
 * `captcha_challenge_id` as `required|string` but never look at
 * the value, so a bot POSTing any string passed.
 *
 * The fix routes the field through `CaptchaConsumer::consume()` which
 * requires:
 *   - the row exists
 *   - status === 'verified' (i.e. the trace was actually validated
 *     server-side, not just claimed by the client)
 *   - user_id matches the caller (or is null on the anonymous path)
 *   - the row hasn't already been consumed by another claim
 *
 * Three tests below — one per surface — exercise the bypass attempt
 * (POST with status=issued, status=consumed, missing row) and assert
 * the rejection.
 */
class CaptchaRequiredAtCompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_shortlink_complete_rejects_unverified_captcha(): void
    {
        $user = $this->user();
        ShortlinkProviderCredential::create([
            'name' => 'mock', 'label' => 'mock', 'transport' => 'query',
            'api_base' => 'https://m', 'api_token' => 'tk',
            'is_active' => true, 'reward_sat' => 5, 'hold_seconds' => 5,
            'daily_limit_per_user' => 10,
        ]);

        $click = ShortlinkClick::create([
            'user_id' => $user->id,
            'provider_name' => 'mock',
            'reward_sat' => 5, 'hold_seconds' => 5,
            'epoch_token' => 'sc_'.bin2hex(random_bytes(14)),
            'status' => 'pending',
            'started_at' => Carbon::now()->subSeconds(7),
        ]);

        // Try with a freshly-issued (NOT yet verified) challenge — bot
        // pattern: skip the captcha widget entirely, scrape an issued
        // challenge id, POST /complete.
        $issued = $this->seedChallenge('issued', $user);

        $r = $this->actingAs($user)->postJson("/api/shortlinks/{$click->id}/complete", [
            'epoch_token' => $click->epoch_token,
            'captcha_challenge_id' => $issued->challenge_id,
        ]);

        $r->assertStatus(422)->assertJson(['error' => 'captcha_required']);
        $this->assertSame('pending', ShortlinkClick::find($click->id)->status,
            'click must remain pending so the legitimate user can still claim');
    }

    public function test_ptc_complete_rejects_made_up_challenge_id(): void
    {
        $user = $this->user();
        $ad = PtcAd::create([
            'source' => 'mock', 'external_id' => 'ad-'.uniqid(),
            'title' => 'x', 'target_url' => 'https://e.x', 'reward_sat' => 5,
            'duration_sec' => 5, 'daily_limit_per_user' => 5,
            'is_active' => true, 'status' => 'approved',
        ]);
        $view = PtcView::create([
            'user_id' => $user->id, 'ptc_ad_id' => $ad->id,
            'epoch_token' => 'pv_'.bin2hex(random_bytes(14)),
            'status' => 'pending',
            'started_at' => Carbon::now()->subSeconds(10),
            'heartbeats_received' => 3, 'heartbeats_expected' => 3,
        ]);

        $r = $this->actingAs($user)->postJson("/api/ptc/{$view->id}/complete", [
            'epoch_token' => $view->epoch_token,
            'captcha_challenge_id' => 'totally-fake-id',
        ]);

        $r->assertStatus(422)->assertJson(['error' => 'captcha_required']);
    }

    public function test_internal_article_complete_rejects_already_consumed_captcha(): void
    {
        $user = $this->user();
        $article = InternalArticle::create([
            'title' => 'a', 'body' => 'b',
            'reward_sat' => 5, 'read_seconds' => 5, 'daily_limit_per_user' => 3,
            'is_active' => true,
        ]);
        $view = InternalArticleView::create([
            'user_id' => $user->id, 'internal_article_id' => $article->id,
            'reward_sat' => 5, 'read_seconds' => 5,
            'epoch_token' => 'ia_'.bin2hex(random_bytes(14)),
            'status' => 'pending',
            'started_at' => Carbon::now()->subSeconds(7),
        ]);

        // First valid claim — consumes the captcha.
        $verified = $this->seedChallenge('verified', $user);
        $first = $this->actingAs($user)->postJson(
            "/api/internal-articles/auth/{$view->epoch_token}/complete",
            ['epoch_token' => $view->epoch_token, 'captcha_challenge_id' => $verified->challenge_id],
        );
        $first->assertOk();
        $this->assertSame('consumed', CaptchaChallenge::find($verified->id)->status);

        // Second view; same user; trying to reuse the now-consumed challenge id.
        $view2 = InternalArticleView::create([
            'user_id' => $user->id, 'internal_article_id' => $article->id,
            'reward_sat' => 5, 'read_seconds' => 5,
            'epoch_token' => 'ia_'.bin2hex(random_bytes(14)),
            'status' => 'pending',
            'started_at' => Carbon::now()->subSeconds(7),
        ]);
        $r = $this->actingAs($user)->postJson(
            "/api/internal-articles/auth/{$view2->epoch_token}/complete",
            ['epoch_token' => $view2->epoch_token, 'captcha_challenge_id' => $verified->challenge_id],
        );

        $r->assertStatus(422)->assertJson(['error' => 'captcha_required']);
    }

    public function test_consumer_rejects_challenge_belonging_to_a_different_user(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        ShortlinkProviderCredential::create([
            'name' => 'mock', 'label' => 'mock', 'transport' => 'query',
            'api_base' => 'https://m', 'api_token' => 'tk',
            'is_active' => true, 'reward_sat' => 5, 'hold_seconds' => 5,
            'daily_limit_per_user' => 10,
        ]);
        $click = ShortlinkClick::create([
            'user_id' => $stranger->id,
            'provider_name' => 'mock',
            'reward_sat' => 5, 'hold_seconds' => 5,
            'epoch_token' => 'sc_'.bin2hex(random_bytes(14)),
            'status' => 'pending',
            'started_at' => Carbon::now()->subSeconds(7),
        ]);
        // owner solves a captcha on their session → verified row tied to owner.
        $stolen = $this->seedChallenge('verified', $owner);

        // stranger tries to consume owner's verified challenge to settle
        // their own click. The user_id mismatch in the consumer must
        // reject this even though the row is verified.
        $r = $this->actingAs($stranger)->postJson("/api/shortlinks/{$click->id}/complete", [
            'epoch_token' => $click->epoch_token,
            'captcha_challenge_id' => $stolen->challenge_id,
        ]);

        $r->assertStatus(422)->assertJson(['error' => 'captcha_required']);
        $this->assertSame('verified', CaptchaChallenge::find($stolen->id)->status,
            'stolen challenge must NOT be consumed — owner can still legitimately use it');
    }

    public function test_consumer_rejects_anonymous_challenge_consumed_from_authenticated_session(): void
    {
        // Threat: an attacker solves a real captcha at the login form
        // (where ChallengeBuilder issues user_id=null), captures the
        // resulting challenge_id from the verify response, then POSTs
        // it to /api/shortlinks/{id}/complete from a separate
        // authenticated session — one solve, one free reward. The
        // strict user-binding fix (consumer requires row.user_id ===
        // caller.id) closes this. A null user_id on the row must be
        // rejected when the caller is authenticated.
        $user = $this->user();
        ShortlinkProviderCredential::create([
            'name' => 'mock', 'label' => 'mock', 'transport' => 'query',
            'api_base' => 'https://m', 'api_token' => 'tk',
            'is_active' => true, 'reward_sat' => 5, 'hold_seconds' => 5,
            'daily_limit_per_user' => 10,
        ]);
        $click = ShortlinkClick::create([
            'user_id' => $user->id,
            'provider_name' => 'mock',
            'reward_sat' => 5, 'hold_seconds' => 5,
            'epoch_token' => 'sc_'.bin2hex(random_bytes(14)),
            'status' => 'pending',
            'started_at' => Carbon::now()->subSeconds(7),
        ]);
        // Anonymous-issued, verified at login form. user_id stays null.
        $anonChallenge = $this->seedChallenge('verified', user: null);

        $r = $this->actingAs($user)->postJson("/api/shortlinks/{$click->id}/complete", [
            'epoch_token' => $click->epoch_token,
            'captcha_challenge_id' => $anonChallenge->challenge_id,
        ]);

        $r->assertStatus(422)->assertJson(['error' => 'captcha_required']);
        $this->assertSame('verified', CaptchaChallenge::find($anonChallenge->id)->status,
            'unbound challenge must stay verified — only matching-user consumes succeed');
    }

    private function user(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    private function seedChallenge(string $status, ?User $user = null): CaptchaChallenge
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $issuedAt = Carbon::now()->subSeconds(3);

        return CaptchaChallenge::create([
            'challenge_id' => 'cc_'.uniqid(),
            'user_id' => $user?->id,
            'session_id' => 'test',
            'provider' => 'trajectory_trace',
            'seed' => 'test-seed',
            'expected_shape' => $shape,
            'fingerprint_hash' => null,
            'client_ip' => '127.0.0.1',
            'ja4' => null,
            'user_agent' => 'phpunit',
            'status' => $status,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->copy()->addSeconds(60),
        ]);
    }
}
