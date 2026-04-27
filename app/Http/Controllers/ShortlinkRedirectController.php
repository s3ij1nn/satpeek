<?php

namespace App\Http\Controllers;

use App\Models\ShortlinkClick;
use App\Shortlinks\Providers\ShortenerException;
use App\Shortlinks\ShortlinkProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Server-side click redirector at `/sl/{token}`.
 *
 * Why this exists:
 *
 *   The previous design returned the shortened destination URL inside the
 *   `/api/shortlinks/{id}/start` JSON. A bot fleet could XHR `/start`,
 *   read the destination, and skip the hold without ever opening it.
 *   Even with per-click rotation the shortened URL was visible in JSON.
 *
 *   `/sl/{token}` keeps the shortened URL off the wire to JS entirely:
 *     - `/start` returns a SatPeek URL (`/sl/{token}`) instead of the
 *       shortened URL.
 *     - The user's browser navigates to `/sl/{token}` in a new tab.
 *     - This handler validates ownership + pending state, mints a FRESH
 *       shortened URL via the configured provider, then 302's the user.
 *     - The shortened URL is only constructed on follow time and is
 *       distinct per request thanks to a query-string cache-buster, so
 *       a bot scraping `/sl/{token}` via XHR consumes provider quota
 *       without learning a single stable destination.
 *
 * Failure modes:
 *
 *   - Token unknown / not owned by the caller → 404.
 *   - Click already verified or rejected → 410 (single-use after
 *     resolution; the auth-landing page does the same).
 *   - Shortener throws → 302 to the cached `target_url` so the viewer's
 *     click isn't wasted. Logged for triage.
 */
class ShortlinkRedirectController extends Controller
{
    public function __construct(private readonly ShortlinkProviderRegistry $shorteners) {}

    public function show(Request $request, string $token): RedirectResponse
    {
        $click = ShortlinkClick::where('user_id', $request->user()->id)
            ->where('epoch_token', $token)
            ->first();

        if (! $click) {
            throw new NotFoundHttpException('Click not found.');
        }
        if ($click->status !== 'pending') {
            throw new HttpException(410, 'This click has already been resolved.');
        }

        $link = $click->shortlink;
        $url = $this->resolveRedirectUrl($link);

        // 302 (not 301) — we explicitly want browsers + scrapers to NOT
        // cache the destination. Each follow has to re-hit /sl/{token}.
        return redirect()->away($url, 302);
    }

    private function resolveRedirectUrl($link): string
    {
        if (! $link->rotates()) {
            // Static shortlinks (no provider_name) keep the legacy
            // behaviour: hand back the stored target_url verbatim.
            return (string) $link->target_url;
        }

        try {
            $client = $this->shorteners->get((string) $link->provider_name);
            if (! $client->isConfigured()) {
                throw new ShortenerException("provider `{$link->provider_name}` has no token");
            }
            // Cache-buster forces shorteners that de-dupe server-side
            // (btcut / cuty / exe / shrtfly) to mint a distinct slug per
            // call. The destination treats unknown query params as noise.
            $rotatedSource = self::appendCacheBuster((string) $link->source_url);
            $fresh = $client->shorten($rotatedSource);
            $link->forceFill([
                'target_url' => $fresh,
                'target_url_rotated_at' => Carbon::now(),
            ])->save();

            return $fresh;
        } catch (ShortenerException $e) {
            Log::warning('shortlink rotation failed at /sl redirector — serving stale target_url', [
                'shortlink' => $link->id,
                'provider' => $link->provider_name,
                'err' => $e->getMessage(),
            ]);

            return (string) $link->target_url;
        }
    }

    private static function appendCacheBuster(string $url): string
    {
        $separator = parse_url($url, PHP_URL_QUERY) === null ? '?' : '&';

        return $url.$separator.'_r='.Str::lower(Str::random(8));
    }
}
