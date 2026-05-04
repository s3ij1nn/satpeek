<?php

use App\Http\Controllers\Webhook\BitcoTaskCallbackController;
use Illuminate\Support\Facades\Route;

// BitcoTasks postback. Static URL — security comes from the form-field
// MD5 signature + IP allowlist (see BitcoTaskAdapter docblock). The
// legacy /{token} segment was a pre-spec defence-in-depth that the
// documented signature scheme makes redundant. Operator sets THIS URL
// (no token suffix) in their BitcoTasks dashboard's Postback URL field.
Route::post('/bitcotask', BitcoTaskCallbackController::class)->name('bitcotask');

// /faucetpay endpoint deliberately removed: FaucetPay does not provide
// outbound webhooks today, and the placeholder we used to ship returned
// `{"ok":true}` to every POST with no signature / IP check. If FaucetPay
// adds outbound callbacks later, re-add the route AND a controller that
// actually verifies the signature + restricts the source IP to
// FaucetPay's published egress range.
