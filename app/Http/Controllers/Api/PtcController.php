<?php

namespace App\Http\Controllers\Api;

use App\BotDetection\PolicyEnforcer;
use App\Enums\EarnSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\BalanceLedger;
use App\Models\PtcAd;
use App\Models\PtcView;
use App\Services\EarnSessionClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PtcController extends Controller
{
    public function __construct(
        private readonly PolicyEnforcer $policy,
        private readonly EarnSessionClaimService $claimService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $ads = self::servableAdsQuery($user->id)
            ->orderByDesc('reward_sat')
            ->limit(50)
            ->get();

        $today = Carbon::now()->startOfDay();
        $usedToday = PtcView::where('user_id', $user->id)
            ->where('created_at', '>=', $today)
            ->where('status', 'verified')
            ->groupBy('ptc_ad_id')
            ->select('ptc_ad_id', DB::raw('count(*) as used'))
            ->pluck('used', 'ptc_ad_id');

        $payload = $ads->map(fn ($ad) => [
            'id' => $ad->id,
            'title' => $ad->title,
            'description' => $ad->description,
            'reward_sat' => $ad->reward_sat,
            'duration_sec' => $ad->duration_sec,
            'remaining_today' => max(0, $ad->daily_limit_per_user - (int) ($usedToday[$ad->id] ?? 0)),
        ]);

        return response()->json(['data' => $payload]);
    }

    public function start(Request $request, int $adId): JsonResponse
    {
        $user = $request->user();
        if (! $this->policy->canStartEarningSession($user)) {
            return response()->json(['error' => 'tier_blocked'], 403);
        }
        // Use the same `servableAdsQuery` filter so users can never start views
        // on their own ads, ads pending review, paused, or out of budget.
        $ad = self::servableAdsQuery($user->id)->findOrFail($adId);

        $usedToday = PtcView::where('user_id', $user->id)
            ->where('ptc_ad_id', $ad->id)
            ->where('status', 'verified')
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->count();
        if ($usedToday >= $ad->daily_limit_per_user) {
            return response()->json(['error' => 'daily_limit_reached'], 429);
        }

        $heartbeatsExpected = max(3, (int) ceil($ad->duration_sec / 2));

        $view = PtcView::create([
            'user_id' => $user->id,
            'ptc_ad_id' => $ad->id,
            'epoch_token' => 'pv_'.Str::lower(Str::random(28)),
            'status' => 'pending',
            'heartbeats_received' => 0,
            'heartbeats_expected' => $heartbeatsExpected,
            'started_at' => Carbon::now(),
        ]);

        return response()->json([
            'view_id' => $view->id,
            'epoch_token' => $view->epoch_token,
            'redirect_url' => $ad->target_url,
            'duration_sec' => $ad->duration_sec,
            'heartbeats_expected' => $heartbeatsExpected,
        ]);
    }

    public function heartbeat(Request $request, int $viewId): JsonResponse
    {
        $view = PtcView::where('user_id', $request->user()->id)->findOrFail($viewId);

        return $this->runHeartbeat($request, $view);
    }

    /**
     * Token-keyed heartbeat — pairs with the /ptc/auth/{token} viewer URL.
     * Resolving by epoch_token (28-char random) instead of the predictable
     * numeric view_id removes ID enumeration as an attack surface.
     */
    public function heartbeatByToken(Request $request, string $token): JsonResponse
    {
        $view = PtcView::where('user_id', $request->user()->id)
            ->where('epoch_token', $token)
            ->first();
        if (! $view) {
            return response()->json(['error' => 'view_not_found'], 404);
        }

        return $this->runHeartbeat($request, $view);
    }

    public function complete(Request $request, int $viewId): JsonResponse
    {
        $view = PtcView::where('user_id', $request->user()->id)->findOrFail($viewId);

        return $this->finishView($request, $view);
    }

    /** Token-keyed completion — pairs with /ptc/auth/{token}. */
    public function completeByToken(Request $request, string $token): JsonResponse
    {
        $view = PtcView::where('user_id', $request->user()->id)
            ->where('epoch_token', $token)
            ->first();
        if (! $view) {
            return response()->json(['error' => 'view_not_found'], 404);
        }

        return $this->finishView($request, $view);
    }

    private function runHeartbeat(Request $request, PtcView $view): JsonResponse
    {
        if ($view->status !== EarnSessionStatus::Pending) {
            return response()->json(['error' => 'view_not_pending'], 422);
        }
        $request->validate([
            'epoch_token' => ['required', 'string'],
            'beacon_at_ms' => ['required', 'integer'],
        ]);
        if (! hash_equals($view->epoch_token, (string) $request->input('epoch_token'))) {
            return response()->json(['error' => 'token_mismatch'], 422);
        }
        $view->increment('heartbeats_received');

        return response()->json(['ok' => true]);
    }

    private function finishView(Request $request, PtcView $view): JsonResponse
    {
        $request->validate([
            'epoch_token' => ['required', 'string'],
            'captcha_challenge_id' => ['required', 'string'],
        ]);

        $reward = (int) $view->ad->reward_sat;
        $minHeartbeats = (int) ceil($view->heartbeats_expected * 0.7);

        $result = $this->claimService->claim(
            session: $view,
            user: $request->user(),
            providedToken: (string) $request->input('epoch_token'),
            captchaId: (string) $request->input('captcha_challenge_id'),
            notPendingError: 'view_not_pending',
            minElapsedSeconds: $view->ad->duration_sec - 1,
            reason: BalanceLedger::REASON_PTC_VIEW,
            referenceType: PtcView::class,
            rewardSat: $reward,
            // PTC-specific gate: at least 70% of expected heartbeats must
            // have arrived. Lower means the user opened the tab in the
            // background or scripted past the timer; reject the row with
            // an explicit reason so triage can tell heartbeat fraud apart
            // from too_fast.
            preClaim: function () use ($view, $minHeartbeats): ?string {
                return $view->heartbeats_received < $minHeartbeats
                    ? 'heartbeat_deficit'
                    : null;
            },
            // PTC-specific post-credit hook: decrement the advertiser's
            // view budget for user-submitted ads (admin rows carry no
            // budget). Eloquent's decrement() refreshes the in-memory
            // attribute so a second SELECT isn't needed to detect
            // exhaustion.
            postCredit: function () use ($view): void {
                $ad = $view->ad;
                if ($ad->user_id !== null) {
                    $ad->decrement('views_remaining');
                    if ((int) $ad->views_remaining <= 0) {
                        $ad->update(['status' => 'completed', 'is_active' => false]);
                    }
                }
            },
        );

        if (! $result->ok) {
            return response()->json(['error' => $result->errorCode], $result->httpStatus);
        }

        return response()->json(['ok' => true, 'reward_sat' => $result->rewardSat]);
    }

    /**
     * Returns a query scope for ads currently servable to a given viewer:
     *   - approved + active + not expired
     *   - user-submitted ads must still have budget (views_remaining > 0)
     *   - excludes the viewer's own ads
     */
    public static function servableAdsQuery(?int $excludeUserId = null)
    {
        return PtcAd::query()
            ->where('is_active', true)
            ->where('status', 'approved')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->where(function ($q) {
                // Admin/system rows (user_id null) have no budget — always served.
                // User-submitted rows need views_remaining > 0.
                $q->whereNull('user_id')->orWhere('views_remaining', '>', 0);
            })
            ->when($excludeUserId !== null, function ($q) use ($excludeUserId) {
                $q->where(function ($q2) use ($excludeUserId) {
                    $q2->whereNull('user_id')->orWhere('user_id', '!=', $excludeUserId);
                });
            });
    }
}
