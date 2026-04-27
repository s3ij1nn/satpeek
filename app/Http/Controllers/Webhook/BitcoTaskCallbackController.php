<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\BalanceLedger;
use App\Models\PtcView;
use App\Models\User;
use App\Offerwall\AdapterRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BitcoTaskCallbackController extends Controller
{
    public function __construct(private readonly AdapterRegistry $registry) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $expected = (string) config('satpeek.bitcotask.s2s_secret');
        if ($expected === '' || ! hash_equals($expected, $token)) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $adapter = $this->registry->get('bitcotask');
        if (! $adapter) {
            return response()->json(['error' => 'adapter_not_registered'], 503);
        }

        $result = $adapter->verifyCallback($request);
        if (! $result) {
            return response()->json(['error' => 'verification_failed'], 401);
        }

        if ($result->status !== 'completed' || $result->rewardSat <= 0 || $result->userId === null) {
            return response()->json(['ok' => true, 'noop' => true]);
        }

        $user = User::find($result->userId);
        if (! $user) {
            Log::warning('bitcotask callback for unknown user', ['user_id' => $result->userId]);

            return response()->json(['error' => 'unknown_user'], 404);
        }

        DB::transaction(function () use ($user, $result) {
            BalanceLedger::create([
                'user_id' => $user->id,
                'delta_sat' => $result->rewardSat,
                'reason' => 'bitcotask_callback',
                'reference_type' => PtcView::class,
                'reference_id' => null,
                'meta' => $result->meta,
            ]);
            $user->increment('balance_sat', $result->rewardSat);
            $user->increment('total_earned_sat', $result->rewardSat);
        });

        return response()->json(['ok' => true, 'credited_sat' => $result->rewardSat, 'at' => Carbon::now()->toIso8601String()]);
    }
}
