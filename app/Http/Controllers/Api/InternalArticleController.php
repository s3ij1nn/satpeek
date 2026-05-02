<?php

namespace App\Http\Controllers\Api;

use App\BotDetection\PolicyEnforcer;
use App\Http\Controllers\Controller;
use App\Models\BalanceLedger;
use App\Models\InternalArticle;
use App\Models\InternalArticleView;
use App\Services\ReferralPayout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Internal "read & earn" article flow.
 *
 * Mirrors the shortlink + PTC structure for consistency:
 *   - GET /api/internal-articles            list active articles + per-user remaining
 *   - POST /api/internal-articles/start/{id} mint a fresh InternalArticleView, return token
 *   - POST /api/internal-articles/auth/{token}/complete  captcha + claim
 *
 * Defence-in-depth identical to ShortlinkController::finishClick():
 * status=pending precheck plus an atomic claim UPDATE so concurrent
 * /complete posts can't double-credit. The DB-level partial UNIQUE on
 * `balance_ledgers (reason, reference_type, reference_id)` already
 * covers `internal_article` triples by virtue of the index shape.
 */
class InternalArticleController extends Controller
{
    public function __construct(
        private readonly PolicyEnforcer $policy,
        private readonly ReferralPayout $referralPayout,
    ) {}

    /**
     * Active articles ordered by reward desc.
     *
     * @return Collection<int, InternalArticle>
     */
    public static function activeArticles(): Collection
    {
        return InternalArticle::query()
            ->where('is_active', true)
            ->orderByDesc('reward_sat')
            ->orderBy('id')
            ->get();
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => self::activeArticles()->map(fn (InternalArticle $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'reward_sat' => $a->reward_sat,
                'read_seconds' => $a->read_seconds,
                'daily_limit_per_user' => $a->daily_limit_per_user,
            ]),
        ]);
    }

    public function start(Request $request, int $articleId): JsonResponse
    {
        $user = $request->user();
        if (! $this->policy->canStartPtcView($user)) {
            return response()->json(['error' => 'tier_blocked'], 403);
        }

        $article = InternalArticle::query()
            ->where('id', $articleId)
            ->where('is_active', true)
            ->first();
        if (! $article) {
            return response()->json(['error' => 'article_unavailable'], 404);
        }

        $usedToday = InternalArticleView::query()
            ->where('user_id', $user->id)
            ->where('internal_article_id', $article->id)
            ->where('status', 'verified')
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->count();
        if ($usedToday >= $article->daily_limit_per_user) {
            return response()->json(['error' => 'daily_limit_reached'], 429);
        }

        $view = InternalArticleView::create([
            'user_id' => $user->id,
            'internal_article_id' => $article->id,
            'reward_sat' => (int) $article->reward_sat,
            'read_seconds' => (int) $article->read_seconds,
            'epoch_token' => 'ia_'.Str::lower(Str::random(28)),
            'status' => 'pending',
            'started_at' => Carbon::now(),
        ]);

        return response()->json([
            'view_id' => $view->id,
            'epoch_token' => $view->epoch_token,
            'redirect_url' => route('internal_articles.read', ['token' => $view->epoch_token]),
            'read_seconds' => $view->read_seconds,
            'reward_sat' => $view->reward_sat,
        ]);
    }

    public function completeByToken(Request $request, string $token): JsonResponse
    {
        $view = InternalArticleView::where('user_id', $request->user()->id)
            ->where('epoch_token', $token)
            ->first();
        if (! $view) {
            return response()->json(['error' => 'view_not_found'], 404);
        }

        return $this->finishView($request, $view);
    }

    private function finishView(Request $request, InternalArticleView $view): JsonResponse
    {
        $user = $request->user();
        if ($view->status !== 'pending') {
            return response()->json(['error' => 'view_not_pending'], 422);
        }
        $request->validate([
            'epoch_token' => ['required', 'string'],
            'captcha_challenge_id' => ['required', 'string'],
        ]);
        if (! hash_equals($view->epoch_token, (string) $request->input('epoch_token'))) {
            return response()->json(['error' => 'token_mismatch'], 422);
        }

        // Read-time floor: the user must have spent at least
        // read_seconds-1 on the page since /start. The auth view's JS
        // disables the claim button until the timer expires; the server
        // check is the backstop for a tampered client.
        $elapsed = (int) abs($view->started_at->diffInSeconds(Carbon::now()));
        $minRead = $view->read_seconds - 1;
        if ($elapsed < $minRead) {
            $view->update(['status' => 'rejected', 'rejection_reason' => 'too_fast', 'completed_at' => Carbon::now()]);

            return response()->json(['error' => 'too_fast'], 422);
        }

        $reward = (int) $view->reward_sat;
        $credited = DB::transaction(function () use ($user, $view, $reward) {
            // Atomic claim mirrors shortlink + PTC: only one concurrent
            // /complete flips the row out of pending. Losing requests
            // see affected_rows=0 and bail without crediting. The
            // partial UNIQUE on balance_ledgers covers the DB-layer
            // double-credit invariant for this reason+ref_type triple.
            $claimed = InternalArticleView::where('id', $view->id)
                ->where('status', 'pending')
                ->update(['status' => 'verified', 'completed_at' => Carbon::now()]);
            if ($claimed === 0) {
                return false;
            }

            BalanceLedger::create([
                'user_id' => $user->id,
                'delta_sat' => $reward,
                'reason' => 'internal_article',
                'reference_type' => InternalArticleView::class,
                'reference_id' => $view->id,
            ]);
            $user->increment('balance_sat', $reward);
            $user->increment('total_earned_sat', $reward);
            $this->referralPayout->settle($user, $reward, InternalArticleView::class, $view->id);

            return true;
        });

        if (! $credited) {
            return response()->json(['error' => 'view_not_pending'], 422);
        }

        return response()->json(['ok' => true, 'reward_sat' => $reward]);
    }
}
