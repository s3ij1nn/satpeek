<?php

namespace App\Http\Controllers\Api;

use App\BotDetection\PolicyEnforcer;
use App\Http\Controllers\Controller;
use App\Models\BalanceLedger;
use App\Models\Shortlink;
use App\Models\ShortlinkClick;
use App\Shortlinks\Providers\ShortenerException;
use App\Shortlinks\ShortlinkProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShortlinkController extends Controller
{
    public function __construct(
        private readonly PolicyEnforcer $policy,
        private readonly ShortlinkProviderRegistry $shorteners,
    ) {}

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
            'redirect_url' => $this->resolveRedirectUrl($link),
            'hold_seconds' => $link->hold_seconds,
        ]);
    }

    /**
     * Returns the URL to hand the viewer for this click. When the shortlink
     * is rotation-enabled (provider_name + source_url set) we re-shorten the
     * canonical destination through the configured provider on every click,
     * so two viewers never see the same shortened URL — that breaks blocklists,
     * defeats "I've seen this one already, just skip" pattern recognition,
     * and forces extension heuristics back to per-domain detection.
     *
     * On any shortener failure we degrade gracefully to whatever target_url
     * is currently stored: better to deliver a slightly-stale URL than to
     * 500 the click and rob the viewer of their reward.
     */
    private function resolveRedirectUrl(Shortlink $link): string
    {
        if (! $link->rotates()) {
            return (string) $link->target_url;
        }

        try {
            $client = $this->shorteners->get((string) $link->provider_name);
            if (! $client->isConfigured()) {
                throw new ShortenerException("provider `{$link->provider_name}` has no token");
            }
            // Cache-buster: btcut.io and friends de-dupe by destination URL
            // server-side and return the same shortened slug for repeat calls.
            // A short random query param is harmless to the destination
            // (servers ignore unknown params) but forces the shortener to
            // mint a fresh slug per rotation.
            $rotatedSource = self::appendCacheBuster((string) $link->source_url);
            $fresh = $client->shorten($rotatedSource);
            $link->forceFill([
                'target_url' => $fresh,
                'target_url_rotated_at' => Carbon::now(),
            ])->save();
            return $fresh;
        } catch (ShortenerException $e) {
            Log::warning('shortlink rotation failed — serving stale target_url', [
                'shortlink' => $link->id,
                'provider' => $link->provider_name,
                'err' => $e->getMessage(),
            ]);
            return (string) $link->target_url;
        }
    }

    /**
     * Append a short random query param so a shortener that de-dupes
     * server-side (btcut.io / cuty.io / exe.io / shrtfly.com all do)
     * issues a distinct slug per rotation. The destination server treats
     * unknown query params as noise — no behavioural impact on the actual
     * landing experience.
     */
    private static function appendCacheBuster(string $url): string
    {
        $separator = parse_url($url, PHP_URL_QUERY) === null ? '?' : '&';
        return $url.$separator.'_r='.Str::lower(Str::random(8));
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
