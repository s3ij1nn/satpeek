<?php

namespace App\Http\Controllers\Api;

use App\BotDetection\PolicyEnforcer;
use App\Http\Controllers\Controller;
use App\Models\BalanceLedger;
use App\Models\Shortlink;
use App\Models\ShortlinkClick;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShortlinkController extends Controller
{
    public function __construct(private readonly PolicyEnforcer $policy) {}

    public function index(Request $request): JsonResponse
    {
        $links = Shortlink::where('is_active', true)->orderByDesc('reward_sat')->limit(50)->get();
        return response()->json([
            'data' => $links->map(fn ($l) => [
                'id' => $l->id,
                'title' => $l->title,
                'reward_sat' => $l->reward_sat,
                'hold_seconds' => $l->hold_seconds,
            ]),
        ]);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $this->policy->canStartPtcView($user)) {
            return response()->json(['error' => 'tier_blocked'], 403);
        }
        $link = Shortlink::where('is_active', true)->findOrFail($id);

        $usedToday = ShortlinkClick::where('user_id', $user->id)
            ->where('shortlink_id', $link->id)
            ->where('status', 'verified')
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->count();
        if ($usedToday >= $link->daily_limit_per_user) {
            return response()->json(['error' => 'daily_limit_reached'], 429);
        }

        $click = ShortlinkClick::create([
            'user_id' => $user->id,
            'shortlink_id' => $link->id,
            'epoch_token' => 'sc_'.Str::lower(Str::random(28)),
            'status' => 'pending',
            'started_at' => Carbon::now(),
        ]);

        return response()->json([
            'click_id' => $click->id,
            'epoch_token' => $click->epoch_token,
            'redirect_url' => $link->target_url,
            'hold_seconds' => $link->hold_seconds,
        ]);
    }

    public function complete(Request $request, int $clickId): JsonResponse
    {
        $user = $request->user();
        $click = ShortlinkClick::where('user_id', $user->id)->findOrFail($clickId);
        if ($click->status !== 'pending') {
            return response()->json(['error' => 'click_not_pending'], 422);
        }
        $request->validate([
            'epoch_token' => ['required', 'string'],
            'captcha_challenge_id' => ['required', 'string'],
        ]);
        if (! hash_equals($click->epoch_token, (string) $request->input('epoch_token'))) {
            return response()->json(['error' => 'token_mismatch'], 422);
        }
        $elapsed = $click->started_at?->diffInSeconds(Carbon::now()) ?? 0;
        if ($elapsed < $click->shortlink->hold_seconds - 1) {
            $click->update(['status' => 'rejected', 'rejection_reason' => 'too_fast', 'completed_at' => Carbon::now()]);
            return response()->json(['error' => 'too_fast'], 422);
        }

        DB::transaction(function () use ($user, $click) {
            $reward = (int) $click->shortlink->reward_sat;
            BalanceLedger::create([
                'user_id' => $user->id,
                'delta_sat' => $reward,
                'reason' => 'shortlink',
                'reference_type' => ShortlinkClick::class,
                'reference_id' => $click->id,
            ]);
            $user->increment('balance_sat', $reward);
            $user->increment('total_earned_sat', $reward);
            $click->update(['status' => 'verified', 'completed_at' => Carbon::now()]);
        });

        return response()->json(['ok' => true, 'reward_sat' => $click->shortlink->reward_sat]);
    }
}
