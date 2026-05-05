<?php

namespace App\Http\Controllers\Api;

use App\BotDetection\PolicyEnforcer;
use App\Http\Controllers\Controller;
use App\Models\BalanceLedger;
use App\Models\ShortlinkClick;
use App\Models\ShortlinkProviderCredential;
use App\Services\EarnSessionClaimService;
use App\Shortlinks\Providers\ShortenerException;
use App\Shortlinks\ShortlinkProviderRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Provider-keyed shortlink earn flow.
 *
 * The model: SatPeek mints a fresh `/shortlinks/auth/{token}` URL,
 * shortens it through the operator-chosen provider (btcut / cuty /
 * exe / shrtfly / ouo), opens the resulting `https://<provider>/<slug>`
 * in a new tab, and pays the user when they come back to the token URL.
 * The provider earns ad revenue from its interstitial; SatPeek's
 * `reward_sat` is paid from that revenue.
 *
 * There is NO inventory of shortlinks. Each row in
 * `shortlink_provider_credentials` is one provider. The user picks a
 * provider; the click flow generates the URL.
 */
class ShortlinkController extends Controller
{
    public function __construct(
        private readonly PolicyEnforcer $policy,
        private readonly EarnSessionClaimService $claimService,
    ) {}

    /**
     * Active, token-configured providers with their per-click economics.
     * Single source of truth used by `index()` and the Blade view.
     *
     * @return Collection<int, ShortlinkProviderCredential>
     */
    public static function enabledProviders(): Collection
    {
        return ShortlinkProviderCredential::query()
            ->where('is_active', true)
            ->whereNotNull('api_token')
            ->orderByDesc('reward_sat')
            ->get();
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => self::enabledProviders()->map(fn (ShortlinkProviderCredential $p) => [
                'name' => $p->name,
                'label' => $p->label ?: $p->name,
                'reward_sat' => $p->reward_sat,
                'hold_seconds' => $p->hold_seconds,
                'daily_limit_per_user' => $p->daily_limit_per_user,
            ]),
        ]);
    }

    /**
     * Mint a fresh ShortlinkClick row, shorten the auth URL via the chosen
     * provider, and return the shortened URL the frontend opens in a new
     * tab. The user completes the provider's interstitial, lands back on
     * `/shortlinks/auth/{token}`, and the auth landing page calls
     * `complete` to settle the reward.
     */
    public function start(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();
        if (! $this->policy->canStartEarningSession($user)) {
            return response()->json(['error' => 'tier_blocked'], 403);
        }

        /** @var ShortlinkProviderCredential|null $providerRow */
        $providerRow = ShortlinkProviderCredential::query()
            ->where('name', $provider)
            ->where('is_active', true)
            ->whereNotNull('api_token')
            ->first();
        if (! $providerRow) {
            return response()->json(['error' => 'provider_unavailable'], 404);
        }

        $usedToday = ShortlinkClick::query()
            ->where('user_id', $user->id)
            ->where('provider_name', $providerRow->name)
            ->where('status', 'verified')
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->count();
        if ($usedToday >= $providerRow->daily_limit_per_user) {
            return response()->json(['error' => 'daily_limit_reached'], 429);
        }

        $click = ShortlinkClick::create([
            'user_id' => $user->id,
            'provider_name' => $providerRow->name,
            // Snapshot the economics so a later config tweak doesn't
            // retroactively change unfinished clicks' rewards.
            'reward_sat' => (int) $providerRow->reward_sat,
            'hold_seconds' => (int) $providerRow->hold_seconds,
            'epoch_token' => 'sc_'.Str::lower(Str::random(28)),
            'status' => 'pending',
            'started_at' => Carbon::now(),
        ]);

        // Build the auth-landing URL the user will return to AFTER
        // completing the provider's interstitial. Append a cache-buster
        // so providers that de-dup by destination URL still mint a
        // distinct slug (mirrors the old rotation logic). The token is
        // already unique per click but the cache-buster covers the
        // edge case where the provider key off URL hash, not body.
        $destination = route('shortlinks.auth', ['token' => $click->epoch_token]).'?_r='.Str::lower(Str::random(8));

        try {
            $client = app(ShortlinkProviderRegistry::class)->get($providerRow->name);
            $shortened = $client->shorten($destination);
        } catch (ShortenerException $e) {
            // Wipe the half-created click so the daily-limit counter
            // doesn't penalise the user for our outage.
            $click->delete();
            Log::warning('shortlink start: shortener failed', [
                'provider' => $providerRow->name,
                'user_id' => $user->id,
                'err' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'provider_failed', 'message' => $e->getMessage()], 502);
        }

        $providerRow->forceFill(['last_used_at' => Carbon::now()])->save();

        return response()->json([
            'click_id' => $click->id,
            'epoch_token' => $click->epoch_token,
            // The actual shortener URL the user opens in a new tab.
            // No /sl/{token} indirection — the destination is the
            // shortener interstitial itself, which is the whole point
            // of this flow (operator earns ad revenue from the click).
            'redirect_url' => $shortened,
            'hold_seconds' => $click->hold_seconds,
            'reward_sat' => $click->reward_sat,
        ]);
    }

    public function complete(Request $request, int $clickId): JsonResponse
    {
        $click = ShortlinkClick::where('user_id', $request->user()->id)->findOrFail($clickId);

        return $this->finishClick($request, $click);
    }

    /**
     * Token-keyed completion — pairs with the /shortlinks/auth/{token}
     * landing flow. Resolving by epoch_token (28-char random) instead of
     * the predictable numeric click_id removes URL probing as an attack
     * vector and lets the same string drive both the page URL and its
     * AJAX completion call.
     */
    public function completeByToken(Request $request, string $token): JsonResponse
    {
        $click = ShortlinkClick::where('user_id', $request->user()->id)
            ->where('epoch_token', $token)
            ->first();
        if (! $click) {
            return response()->json(['error' => 'click_not_found'], 404);
        }

        return $this->finishClick($request, $click);
    }

    private function finishClick(Request $request, ShortlinkClick $click): JsonResponse
    {
        $request->validate([
            'epoch_token' => ['required', 'string'],
            'captcha_challenge_id' => ['required', 'string'],
        ]);

        $reward = $click->effectiveRewardSat();
        // Minimum round-trip floor: at least hold_seconds−1 must have
        // elapsed since /start. Anti-skip-the-shortener gate — the
        // publisher's interstitial takes 5–15 s, so a return faster
        // than that means the user bypassed it. The atomic claim,
        // captcha consumption, balance writes and referral payout all
        // ride through the shared service.
        $result = $this->claimService->claim(
            session: $click,
            user: $request->user(),
            providedToken: (string) $request->input('epoch_token'),
            captchaId: (string) $request->input('captcha_challenge_id'),
            notPendingError: 'click_not_pending',
            minElapsedSeconds: $click->effectiveHoldSeconds() - 1,
            reason: BalanceLedger::REASON_SHORTLINK,
            referenceType: ShortlinkClick::class,
            rewardSat: $reward,
        );

        if (! $result->ok) {
            return response()->json(['error' => $result->errorCode], $result->httpStatus);
        }

        return response()->json(['ok' => true, 'reward_sat' => $result->rewardSat]);
    }
}
