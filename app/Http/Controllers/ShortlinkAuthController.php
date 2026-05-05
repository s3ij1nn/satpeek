<?php

namespace App\Http\Controllers;

use App\Enums\EarnSessionStatus;
use App\Models\ShortlinkClick;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-click landing page (`/shortlinks/auth/{token}`).
 *
 * The user only reaches this page legitimately by going THROUGH the
 * publisher shortener (btcut / cuty / ouo / …) — `/shortlinks` navigates
 * the same tab to the shortener URL, so the only path back to this URL
 * is the shortener's own redirect after its interstitial runs.
 *
 * Each click on `/shortlinks` generates a 28-char random `epoch_token`,
 * giving every claim attempt a fresh, unguessable URL slug. Browser
 * history shows different URLs each session, a leaked URL is single-use
 * and tied to one user, and bulk URL probing
 * (`/shortlinks/auth/<sequential>`) is infeasible against the token space.
 *
 * The post-return hold UI was removed: the user already waited the
 * shortener's own interstitial (5–15 s ad view), so making them wait
 * again on SatPeek's side is gratuitous. The captcha alone is the
 * anti-bot gate at this stage. Server-side, we still enforce a
 * minimum traversal time at claim-time (see ShortlinkController) as
 * defence-in-depth against forged arrivals.
 */
class ShortlinkAuthController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $click = ShortlinkClick::where('user_id', $request->user()->id)
            ->where('epoch_token', $token)
            ->first();

        if (! $click) {
            throw new NotFoundHttpException('Click not found.');
        }

        // Single-use: once a click flips out of `pending`, the URL is dead.
        // Reusing it for a second claim would be a replay attack vector.
        if ($click->status !== EarnSessionStatus::Pending) {
            throw new HttpException(410, 'This click has already been resolved.');
        }

        return view('shortlinks.auth', [
            'click' => $click,
            'reward_sat' => $click->effectiveRewardSat(),
        ]);
    }
}
