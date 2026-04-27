<?php

use App\Http\Controllers\Webhook\BitcoTaskCallbackController;
use App\Http\Controllers\Webhook\FaucetPayWebhookController;
use Illuminate\Support\Facades\Route;

// BitcoTasks postback. Static URL — security comes from the form-field
// MD5 signature + IP allowlist (see BitcoTaskAdapter docblock). The
// legacy /{token} segment was a pre-spec defence-in-depth that the
// documented signature scheme makes redundant. Operator sets THIS URL
// (no token suffix) in their BitcoTasks dashboard's Postback URL field.
Route::post('/bitcotask', BitcoTaskCallbackController::class)->name('bitcotask');
Route::post('/faucetpay', FaucetPayWebhookController::class)->name('faucetpay');
