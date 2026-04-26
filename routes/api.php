<?php

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
    Route::get('/ptc', [PtcController::class, 'index']);
    Route::post('/ptc/{adId}/start', [PtcController::class, 'start']);
    Route::post('/ptc/{viewId}/heartbeat', [PtcController::class, 'heartbeat']);
    Route::post('/ptc/{viewId}/complete', [PtcController::class, 'complete']);

    Route::get('/shortlinks', [ShortlinkController::class, 'index']);
    Route::post('/shortlinks/{id}/start', [ShortlinkController::class, 'start']);
    Route::post('/shortlinks/{clickId}/complete', [ShortlinkController::class, 'complete']);

    Route::post('/withdraw', [WithdrawController::class, 'store']);
});
