<?php

declare(strict_types=1);

namespace Tests\Feature\InternalArticles;

use App\BotDetection\PolicyEnforcer;
use App\Captcha\TrajectoryTraceProvider;
use App\Models\BalanceLedger;
use App\Models\BotScore;
use App\Models\CaptchaChallenge;
use App\Models\InternalArticle;
use App\Models\InternalArticleView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the internal "read & earn" article earn flow:
 *   - GET /api/internal-articles lists active articles
 *   - POST /api/internal-articles/start/{id} mints a fresh view row
 *     with snapshotted reward + read_seconds and returns the
 *     /read-articles/internal/{token} reader URL
 *   - the reader page renders the article body inline (Markdown → HTML)
 *   - completion enforces read_seconds floor + captcha + atomic claim
 *   - the abuse guards (daily limit, tier gate, too-fast, token mismatch,
 *     replayed completion) fire so a viewer can't bypass the read time
 *   - concurrent /complete cannot double-credit (atomic UPDATE +
 *     balance_ledgers UNIQUE backstop)
 */
class ReadFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_active_articles(): void
    {
        $user = User::factory()->create();
        $live = $this->seedArticle(['title' => 'Live read']);
        $this->seedArticle(['title' => 'Off read', 'is_active' => false]);

        $response = $this->actingAs($user)->getJson('/api/internal-articles');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Live read', $titles);
        $this->assertNotContains('Off read', $titles);
    }

    public function test_start_returns_reader_url_and_persists_view_with_snapshot(): void
    {
        $user = User::factory()->create();
        $article = $this->seedArticle(['reward_sat' => 12, 'read_seconds' => 60, 'daily_limit_per_user' => 3]);

        $response = $this->actingAs($user)->postJson("/api/internal-articles/start/{$article->id}");

        $response->assertOk();
        $response->assertJson([
            'reward_sat' => 12,
            'read_seconds' => 60,
            'redirect_url' => route('internal_articles.read', ['token' => $response->json('epoch_token')]),
        ]);
        $token = $response->json('epoch_token');
        $this->assertMatchesRegularExpression('/^ia_[a-z0-9]{28}$/', $token);
        $this->assertDatabaseHas('internal_article_views', [
            'user_id' => $user->id,
            'internal_article_id' => $article->id,
            'reward_sat' => 12,
            'read_seconds' => 60,
            'status' => 'pending',
        ]);
    }

    public function test_start_404s_for_inactive_article(): void
    {
        $user = User::factory()->create();
        $a = $this->seedArticle(['is_active' => false]);

        $this->actingAs($user)->postJson("/api/internal-articles/start/{$a->id}")
            ->assertStatus(404)
            ->assertJson(['error' => 'article_unavailable']);
    }

    public function test_start_blocks_when_user_tier_is_likely_bot(): void
    {
        $user = User::factory()->create();
        BotScore::create([
            'user_id' => $user->id,
            'score' => 0.72,
            'tier' => 'likely_bot',
            'signals' => [],
        ]);
        $this->assertFalse(app(PolicyEnforcer::class)->canStartPtcView($user->fresh()));
        $a = $this->seedArticle();

        $this->actingAs($user)->postJson("/api/internal-articles/start/{$a->id}")
            ->assertStatus(403)
            ->assertJson(['error' => 'tier_blocked']);
        $this->assertDatabaseMissing('internal_article_views', ['user_id' => $user->id]);
    }

    public function test_start_returns_429_when_daily_limit_reached(): void
    {
        $user = User::factory()->create();
        $a = $this->seedArticle(['daily_limit_per_user' => 2]);
        for ($i = 0; $i < 2; $i++) {
            InternalArticleView::create([
                'user_id' => $user->id,
                'internal_article_id' => $a->id,
                'reward_sat' => $a->reward_sat,
                'read_seconds' => $a->read_seconds,
                'epoch_token' => 'ia_used_'.$i.'_'.uniqid(),
                'status' => 'verified',
                'started_at' => Carbon::now()->subMinutes($i + 1),
                'completed_at' => Carbon::now()->subMinutes($i + 1)->addSeconds(60),
            ]);
        }

        $this->actingAs($user)->postJson("/api/internal-articles/start/{$a->id}")
            ->assertStatus(429)
            ->assertJson(['error' => 'daily_limit_reached']);
    }

    public function test_reader_page_renders_markdown_body_inline(): void
    {
        $user = User::factory()->create();
        $a = $this->seedArticle([
            'title' => 'Hello article',
            'body' => "# Heading\n\nA paragraph with **bold** and a [link](https://example.com).",
        ]);
        $start = $this->actingAs($user)->postJson("/api/internal-articles/start/{$a->id}")->json();

        $response = $this->actingAs($user)
            ->get(route('internal_articles.read', ['token' => $start['epoch_token']]));

        $response->assertOk();
        $response->assertSee('Hello article', false);
        $response->assertSee('<h1>Heading</h1>', false);
        $response->assertSee('<strong>bold</strong>', false);
        $response->assertSee('href="https://example.com"', false);
    }

    public function test_reader_page_strips_raw_html_to_prevent_xss(): void
    {
        $user = User::factory()->create();
        $a = $this->seedArticle([
            'body' => "Safe text.\n\n<script>alert('xss')</script>\n\nMore text.",
        ]);
        $start = $this->actingAs($user)->postJson("/api/internal-articles/start/{$a->id}")->json();

        $response = $this->actingAs($user)
            ->get(route('internal_articles.read', ['token' => $start['epoch_token']]));

        $response->assertOk();
        $response->assertDontSee('<script>alert', false);
    }

    public function test_reader_page_404s_for_other_users_token(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $a = $this->seedArticle();
        $start = $this->actingAs($owner)->postJson("/api/internal-articles/start/{$a->id}")->json();

        $this->actingAs($stranger)
            ->get(route('internal_articles.read', ['token' => $start['epoch_token']]))
            ->assertNotFound();
    }

    public function test_reader_page_410s_for_already_resolved_view(): void
    {
        $user = User::factory()->create();
        $a = $this->seedArticle();
        $start = $this->actingAs($user)->postJson("/api/internal-articles/start/{$a->id}")->json();
        InternalArticleView::where('epoch_token', $start['epoch_token'])->update([
            'status' => 'verified',
            'completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('internal_articles.read', ['token' => $start['epoch_token']]))
            ->assertStatus(410);
    }

    public function test_complete_credits_balance_after_full_read(): void
    {
        $user = User::factory()->create(['balance_sat' => 0, 'total_earned_sat' => 0]);
        $a = $this->seedArticle(['reward_sat' => 17, 'read_seconds' => 30]);
        $start = $this->actingAs($user)->postJson("/api/internal-articles/start/{$a->id}")->json();
        InternalArticleView::where('epoch_token', $start['epoch_token'])->update([
            'started_at' => Carbon::now()->subSeconds(35),
        ]);

        $challenge = $this->seedChallenge();
        $challenge->update(['status' => 'verified']);

        $response = $this->actingAs($user)->postJson(
            "/api/internal-articles/auth/{$start['epoch_token']}/complete",
            ['epoch_token' => $start['epoch_token'], 'captcha_challenge_id' => $challenge->challenge_id],
        );

        $response->assertOk()->assertJson(['ok' => true, 'reward_sat' => 17]);
        $this->assertSame(17, (int) $user->fresh()->balance_sat);
        $this->assertSame(17, (int) $user->fresh()->total_earned_sat);
        $this->assertDatabaseHas('balance_ledgers', [
            'user_id' => $user->id,
            'delta_sat' => 17,
            'reason' => 'internal_article',
        ]);
    }

    public function test_complete_rejects_when_read_too_fast(): void
    {
        $user = User::factory()->create(['balance_sat' => 0]);
        $a = $this->seedArticle(['read_seconds' => 60]);
        $start = $this->actingAs($user)->postJson("/api/internal-articles/start/{$a->id}")->json();
        // started_at = now → elapsed ~ 0 << read_seconds.

        $challenge = $this->seedChallenge();
        $challenge->update(['status' => 'verified']);

        $response = $this->actingAs($user)->postJson(
            "/api/internal-articles/auth/{$start['epoch_token']}/complete",
            ['epoch_token' => $start['epoch_token'], 'captcha_challenge_id' => $challenge->challenge_id],
        );

        $response->assertStatus(422)->assertJson(['error' => 'too_fast']);
        $this->assertSame(0, (int) $user->fresh()->balance_sat);
    }

    public function test_complete_is_idempotent_under_concurrent_requests(): void
    {
        $user = User::factory()->create(['balance_sat' => 0]);
        $a = $this->seedArticle(['reward_sat' => 19, 'read_seconds' => 10]);
        $start = $this->actingAs($user)->postJson("/api/internal-articles/start/{$a->id}")->json();
        InternalArticleView::where('epoch_token', $start['epoch_token'])->update([
            'started_at' => Carbon::now()->subSeconds(15),
        ]);
        $challenge = $this->seedChallenge();
        $challenge->update(['status' => 'verified']);

        $first = $this->actingAs($user)->postJson(
            "/api/internal-articles/auth/{$start['epoch_token']}/complete",
            ['epoch_token' => $start['epoch_token'], 'captcha_challenge_id' => $challenge->challenge_id],
        );
        $first->assertOk();
        $this->assertSame(19, (int) $user->fresh()->balance_sat);

        // Force back to pending to mimic a concurrent request that
        // raced through the precheck before the first transaction
        // committed. The DB-level UNIQUE on balance_ledgers
        // (reason, reference_type, reference_id) is the backstop.
        InternalArticleView::where('epoch_token', $start['epoch_token'])->update(['status' => 'pending']);
        try {
            $this->actingAs($user)->postJson(
                "/api/internal-articles/auth/{$start['epoch_token']}/complete",
                ['epoch_token' => $start['epoch_token'], 'captcha_challenge_id' => $challenge->challenge_id],
            );
        } catch (\Throwable) {
            // QueryException from UNIQUE violation is the expected backstop outcome.
        }

        $this->assertSame(19, (int) $user->fresh()->balance_sat, 'must not double-credit');
        $this->assertSame(1, BalanceLedger::where('reference_type', InternalArticleView::class)
            ->where('reference_id', $start['view_id'])
            ->count(), 'exactly one ledger row per view');
    }

    private function seedArticle(array $overrides = []): InternalArticle
    {
        return InternalArticle::create(array_merge([
            'title' => 'Test article',
            'body' => 'Some **markdown** body content.',
            'source_attribution' => null,
            'reward_sat' => 10,
            'read_seconds' => 30,
            'daily_limit_per_user' => 3,
            'is_active' => true,
        ], $overrides));
    }

    private function seedChallenge(): CaptchaChallenge
    {
        $shape = TrajectoryTraceProvider::sampleCurve('sine', 30, 120, 280, 120, 40, 2, 8000, 60);
        $issuedAt = Carbon::now()->subSeconds(3);

        return CaptchaChallenge::create([
            'challenge_id' => 'cc_ia_'.uniqid(),
            'user_id' => null,
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
