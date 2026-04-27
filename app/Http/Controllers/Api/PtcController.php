<?php

namespace App\Http\Controllers\Api;

use App\BotDetection\PolicyEnforcer;
use App\Http\Controllers\Controller;
use App\Models\BalanceLedger;
use App\Models\PtcAd;
use App\Models\PtcView;
use App\Models\Referral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PtcController extends Controller
{
    public function __construct(private readonly PolicyEnforcer $policy) {}

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
        if (! $this->policy->canStartPtcView($user)) {
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
        if ($view->status !== 'pending') {
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

        $minHeartbeats = (int) ceil($view->heartbeats_expected * 0.7);
        if ($view->heartbeats_received < $minHeartbeats) {
            $view->update(['status' => 'rejected', 'rejection_reason' => 'heartbeat_deficit', 'completed_at' => Carbon::now()]);

            return response()->json(['error' => 'heartbeat_deficit'], 422);
        }

        $elapsed = $view->started_at?->diffInSeconds(Carbon::now()) ?? 0;
        if ($elapsed < $view->ad->duration_sec - 1) {
            $view->update(['status' => 'rejected', 'rejection_reason' => 'too_fast', 'completed_at' => Carbon::now()]);

            return response()->json(['error' => 'too_fast'], 422);
        }

        DB::transaction(function () use ($user, $view) {
            $reward = (int) $view->ad->reward_sat;
            BalanceLedger::create([
                'user_id' => $user->id,
                'delta_sat' => $reward,
                'reason' => 'ptc_view',
                'reference_type' => PtcView::class,
                'reference_id' => $view->id,
            ]);
            $user->increment('balance_sat', $reward);
            $user->increment('total_earned_sat', $reward);

            $this->payReferralCommission($user, $reward, PtcView::class, $view->id);

            $view->update(['status' => 'verified', 'completed_at' => Carbon::now()]);

            // Decrement the advertiser's view budget. User-submitted ads only;
            // admin-created rows (user_id = null) have no budget to decrement.
            $ad = $view->ad;
            if ($ad->user_id !== null) {
                $ad->decrement('views_remaining');
                if ((int) $ad->fresh()->views_remaining <= 0) {
                    $ad->update(['status' => 'completed', 'is_active' => false]);
                }
            }
        });

        return response()->json(['ok' => true, 'reward_sat' => $view->ad->reward_sat]);
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

    private function payReferralCommission($user, int $reward, string $refType, int $refId): void
    {
        if (! $user->referrer_id) {
            return;
        }
        $pct = (int) config('satpeek.referral.commission_pct', 10);
        if ($pct <= 0) {
            return;
        }
        $commission = (int) floor($reward * $pct / 100);
        if ($commission <= 0) {
            return;
        }
        BalanceLedger::create([
            'user_id' => $user->referrer_id,
            'delta_sat' => $commission,
            'reason' => 'referral_commission',
            'reference_type' => $refType,
            'reference_id' => $refId,
        ]);
        DB::table('users')->where('id', $user->referrer_id)->increment('balance_sat', $commission);
        DB::table('users')->where('id', $user->referrer_id)->increment('total_earned_sat', $commission);
        Referral::query()
            ->where('referrer_id', $user->referrer_id)
            ->where('referred_id', $user->id)
            ->increment('lifetime_commission_sat', $commission);
    }
}
