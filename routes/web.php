<?php

use App\Http\Controllers\AdvertiseController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InternalArticleAuthController;
use App\Http\Controllers\PtcAuthController;
use App\Http\Controllers\ReadArticlesController;
use App\Http\Controllers\ShortlinkAuthController;
use Illuminate\Support\Facades\Route;

// Public landing — controller (not Route::view) so the page can show
// live stats from PublicStatsBuilder (sat paid out, active inventory,
// 30d bot-rejection rate). Cached 10 min in the service.
Route::get('/', HomeController::class)->name('home');

// Operations health endpoint — JSON payload reporting DB / Redis / MaxMind /
// shortlink provider / IP-reputation provider status. Public so a load balancer
// or external uptime monitor can reach it without credentials. Returns 503
// when a critical component (db / redis) is down so the LB pulls the node;
// returns 200 with `status: degraded` for non-critical issues so monitoring
// can alert without paging.
Route::get('/up', [HealthController::class, 'show'])->name('health');

// Guest-only auth pages
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('ip.gate:70')
        ->name('register.store');

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'showRequest'])->name('password.request');
    // Without throttling, an attacker can hammer this endpoint to
    // bomb a target inbox or enumerate valid email addresses through
    // queue-job timing differences. 5/min/IP matches the email-
    // verification resend rate.
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
});

// Logout (any authenticated user)
Route::post('/logout', LogoutController::class)
    ->middleware('auth')
    ->name('logout');

// Email verification — needs to be reachable while logged in but unverified.
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:verification-send')
        ->name('verification.send');
});

// User-facing pages — auth required, email verification required for withdrawals.
Route::middleware(['auth'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::view('/ptc', 'ptc.index')->name('ptc.index');
    // Per-watch rotating landing URL (security: each watch → fresh 28-char
    // token → unguessable, single-use). Declared BEFORE /ptc/{id} so the
    // numeric pattern doesn't shadow /auth/<token>.
    Route::get('/ptc/auth/{token}', [PtcAuthController::class, 'show'])
        ->where('token', '[A-Za-z0-9_]+')
        ->name('ptc.auth');
    Route::get('/ptc/{id}', function (int $id) {
        return view('ptc.view', ['id' => $id, 'view' => null]);
    })->whereNumber('id')->name('ptc.view');

    Route::view('/shortlinks', 'shortlinks.index')->name('shortlinks.index');
    // Per-click rotating landing URL (security: each click → fresh 28-char
    // token → unguessable, single-use). See ShortlinkAuthController.
    Route::get('/shortlinks/auth/{token}', [ShortlinkAuthController::class, 'show'])
        ->where('token', '[A-Za-z0-9_]+')
        ->name('shortlinks.auth');

    // Read-article tasks — pure pass-through to whichever per-user adapter
    // (BitcoTasks today) is enabled. Renders an empty/disabled state when
    // no partner is configured, so the route is safe to keep registered.
    Route::get('/read-articles', ReadArticlesController::class)->name('read_articles.index');
    // Per-view rotating reader URL for internal admin-managed articles.
    // Same single-use 28-char token pattern as /ptc/auth and
    // /shortlinks/auth — see InternalArticleAuthController.
    Route::get('/read-articles/internal/{token}', [InternalArticleAuthController::class, 'show'])
        ->where('token', '[A-Za-z0-9_]+')
        ->name('internal_articles.read');

    // Withdrawals require email verification — the page renders a warning otherwise.
    Route::view('/withdraw', 'withdraw.index')->name('withdraw.index');

    Route::view('/referral', 'referral.index')->name('referral.index');

    // Self-serve advertising — same account as earning, balance-funded campaigns.
    Route::get('/advertise', [AdvertiseController::class, 'index'])->name('advertise.index');
    Route::get('/advertise/create', [AdvertiseController::class, 'create'])->name('advertise.create');
    Route::post('/advertise', [AdvertiseController::class, 'store'])->name('advertise.store');
    Route::get('/advertise/{id}', [AdvertiseController::class, 'show'])->whereNumber('id')->name('advertise.show');
    Route::get('/advertise/{id}/edit', [AdvertiseController::class, 'edit'])->whereNumber('id')->name('advertise.edit');
    Route::patch('/advertise/{id}', [AdvertiseController::class, 'update'])->whereNumber('id')->name('advertise.update');
});
