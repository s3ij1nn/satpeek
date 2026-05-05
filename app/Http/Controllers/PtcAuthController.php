<?php

namespace App\Http\Controllers;

use App\Enums\EarnSessionStatus;
use App\Models\PtcView;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-watch landing page (`/ptc/auth/{token}`).
 *
 * Each PTC watch session generates a 28-char random `epoch_token` and the
 * user's tab is navigated to this URL. Same security profile as the
 * shortlink auth landing:
 *
 *   - URL slug rotates per watch (no stable string for blocklists / "I've
 *     seen this ad and skipped past it" pattern recognition)
 *   - bulk URL probing (/ptc/auth/<sequential>) is infeasible against the
 *     token space
 *   - leaked URL is single-use AND user-scoped (410 once verified, 404
 *     for any other user)
 */
class PtcAuthController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $view = PtcView::where('user_id', $request->user()->id)
            ->where('epoch_token', $token)
            ->first();

        if (! $view) {
            throw new NotFoundHttpException('Watch session not found.');
        }

        // Single-use: once a view leaves `pending`, the URL is dead. A
        // verified click can't be replayed for a second reward, and a
        // rejected one shouldn't be revisited (re-start from /ptc list).
        if ($view->status !== EarnSessionStatus::Pending) {
            throw new HttpException(410, 'This watch session has already been resolved.');
        }

        return view('ptc.view', [
            'id' => $view->ptc_ad_id,
            'view' => $view,
        ]);
    }
}
