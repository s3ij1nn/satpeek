<?php

namespace App\Http\Controllers;

use App\Models\ShortlinkClick;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-click landing page (`/shortlinks/auth/{token}`).
 *
 * Each click on a shortlink generates a 28-char random `epoch_token` and the
 * user's tab is navigated to this URL. That gives every click a fresh,
 * unguessable URL slug — browser history shows different URLs each session,
 * a leaked URL is single-use and tied to one user, and bulk URL probing
 * (`/shortlinks/auth/<sequential>`) is infeasible against the token space.
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
        if ($click->status !== 'pending') {
            throw new HttpException(410, 'This click has already been resolved.');
        }

        $link = $click->shortlink;
        // started_at is non-nullable on the schema so no ?-> guard needed.
        $elapsedSec = (int) $click->started_at->diffInSeconds(now(), absolute: true);
        $remainingSec = max(0, (int) $link->hold_seconds - $elapsedSec);

        return view('shortlinks.auth', [
            'click' => $click,
            'link' => $link,
            'remaining_sec' => $remainingSec,
        ]);
    }
}
