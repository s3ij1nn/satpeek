<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Receives the browser-side adblock detection report. Authenticated only —
 * no point in tracking adblock for anonymous visitors since the gate
 * applies to earning surfaces (all auth-only).
 *
 * The report shape is:
 *   { adblock_detected: bool, brave_detected: bool, signals: [string, ...] }
 *
 * The user's `adblock_status` flips to:
 *   - `detected` when EITHER `adblock_detected=true` OR `brave_detected=true`
 *   - `clean` otherwise
 *
 * `adblock_checked_at` is set to now() on every successful POST so
 * `AdblockGate` can enforce a freshness window — a bot that simply skips
 * the report can't claim "clean" by default.
 */
class AdblockController extends Controller
{
    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'adblock_detected' => ['required', 'boolean'],
            'brave_detected' => ['required', 'boolean'],
            'signals' => ['nullable', 'array'],
            'signals.*' => ['string', 'max:32'],
        ]);

        $blocked = (bool) $validated['adblock_detected'] || (bool) $validated['brave_detected'];

        $user = $request->user();
        $user->forceFill([
            'adblock_status' => $blocked ? 'detected' : 'clean',
            'adblock_checked_at' => Carbon::now(),
        ])->save();

        return response()->json([
            'status' => $user->adblock_status,
            'checked_at' => $user->adblock_checked_at?->toIso8601String(),
        ]);
    }
}
