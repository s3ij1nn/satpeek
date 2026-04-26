<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FaucetPayWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Placeholder — FaucetPay does not provide outbound webhooks today.
        // Reserved for future integration (e.g. confirmation callbacks).
        return response()->json(['ok' => true]);
    }
}
