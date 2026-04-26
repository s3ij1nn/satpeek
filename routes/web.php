<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

// Public landing
Route::view('/', 'home')->name('home');

// Guest-only auth pages
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('ip.gate:70')
        ->name('register.store');

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'showRequest'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->name('password.email');
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
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

// User-facing pages — auth required, email verification required for withdrawals.
Route::middleware(['auth'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::view('/ptc', 'ptc.index')->name('ptc.index');
    Route::get('/ptc/{id}', function (int $id) {
        return view('ptc.view', ['id' => $id]);
    })->whereNumber('id')->name('ptc.view');

    Route::view('/shortlinks', 'shortlinks.index')->name('shortlinks.index');

    // Withdrawals require email verification — the page renders a warning otherwise.
    Route::view('/withdraw', 'withdraw.index')->name('withdraw.index');

    Route::view('/referral', 'referral.index')->name('referral.index');

    // Self-serve advertising — same account as earning, balance-funded campaigns.
    Route::get('/advertise', [\App\Http\Controllers\AdvertiseController::class, 'index'])->name('advertise.index');
    Route::get('/advertise/create', [\App\Http\Controllers\AdvertiseController::class, 'create'])->name('advertise.create');
    Route::post('/advertise', [\App\Http\Controllers\AdvertiseController::class, 'store'])->name('advertise.store');
    Route::get('/advertise/{id}', [\App\Http\Controllers\AdvertiseController::class, 'show'])->whereNumber('id')->name('advertise.show');
});
