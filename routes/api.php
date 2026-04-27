<?php

use App\Http\Controllers\Api\AdblockController;
use App\Http\Controllers\Api\BeaconController;
use App\Http\Controllers\Api\CaptchaController;
use App\Http\Controllers\Api\PtcController;
use App\Http\Controllers\Api\ShortlinkController;
use App\Http\Controllers\Api\WithdrawController;
use Illuminate\Support\Facades\Route;

// Captcha + telemetry — open to anonymous callers because they're hit by the
// public registration / login forms before the user has a session. Fingerprint
// header is required (FingerprintRequired middleware).
Route::middleware(['fingerprint'])->group(function () {
    Route::get('/captcha/issue', [CaptchaController::class, 'issue'])->name('api.captcha.issue');
    Route::post('/captcha/verify', [CaptchaController::class, 'verify'])->name('api.captcha.verify');
    Route::post('/beacon', [BeaconController::class, 'store'])->name('api.beacon');
});

// State-changing endpoints — use the standard web session guard now that the
// frontend is the same-origin Blade app. The bot.gate middleware blocks any
// account whose tier escalated to `banned`.
Route::middleware(['auth', 'bot.gate'])->group(function () {
    // Anti-adblock report endpoint — needs to land BEFORE adblock.gate
    // applies, otherwise a freshly-checking client gets locked out of
    // the very endpoint they need to call to clear the lock.
    Route::post('/adblock/report', [AdblockController::class, 'report'])->name('api.adblock.report');

    Route::get('/ptc', [PtcController::class, 'index']);
    Route::post('/ptc/{adId}/start', [PtcController::class, 'start'])->middleware('adblock.gate')->whereNumber('adId');
    // Token-keyed endpoints pair with the /ptc/auth/{token} viewer URL —
    // declared BEFORE the legacy {viewId} routes so /auth/<token>/… isn't
    // shadowed by the numeric {viewId} pattern.
    Route::post('/ptc/auth/{token}/heartbeat', [PtcController::class, 'heartbeatByToken'])
        ->where('token', '[A-Za-z0-9_]+');
    Route::post('/ptc/auth/{token}/complete', [PtcController::class, 'completeByToken'])
        ->where('token', '[A-Za-z0-9_]+');
    // Legacy: heartbeat / complete by numeric view ID. Kept for backward
    // compatibility with existing tests + any external callers.
    Route::post('/ptc/{viewId}/heartbeat', [PtcController::class, 'heartbeat'])->whereNumber('viewId');
    Route::post('/ptc/{viewId}/complete', [PtcController::class, 'complete'])->whereNumber('viewId');

    Route::get('/shortlinks', [ShortlinkController::class, 'index']);
    Route::post('/shortlinks/{id}/start', [ShortlinkController::class, 'start'])->middleware('adblock.gate')->whereNumber('id');
    // Token-keyed completion pairs with the /shortlinks/auth/{token} landing —
    // same 28-char random as the URL slug, no numeric ID exposure. Declared
    // BEFORE the legacy clickId route so /auth/<token>/complete doesn't get
    // shadowed by the {clickId} pattern matching.
    Route::post('/shortlinks/auth/{token}/complete', [ShortlinkController::class, 'completeByToken'])
        ->where('token', '[A-Za-z0-9_]+');
    // Legacy: complete-by-numeric-clickId. Kept for tests / external callers.
    Route::post('/shortlinks/{clickId}/complete', [ShortlinkController::class, 'complete'])->whereNumber('clickId');

    Route::post('/withdraw', [WithdrawController::class, 'store'])->middleware('adblock.gate');
});
