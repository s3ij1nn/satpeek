<?php

use App\Http\Controllers\Webhook\BitcoTaskCallbackController;
use App\Http\Controllers\Webhook\FaucetPayWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/bitcotask/{token}', BitcoTaskCallbackController::class)->name('bitcotask');
Route::post('/faucetpay', FaucetPayWebhookController::class)->name('faucetpay');
