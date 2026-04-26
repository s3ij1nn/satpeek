<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BehavioralEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BeaconController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:200'],
            'events.*.kind' => ['required', 'string', 'max:24'],
            'events.*.payload' => ['required'],
            'events.*.observed_at_ms' => ['required', 'integer'],
        ]);

        $userId = $request->user()?->id;
        $sessionId = (string) $request->header('X-SP-Session', $request->cookie('satpeek_sid', ''));
        if ($sessionId === '') {
            $sessionId = '_unbound';
        }
        $ip = $request->ip();

        $rows = [];
        foreach ($validated['events'] as $event) {
            $rows[] = [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'kind' => $event['kind'],
                'payload' => is_array($event['payload']) ? json_encode($event['payload']) : json_encode([$event['payload']]),
                'client_ip' => $ip,
                'observed_at' => Carbon::createFromTimestampMs((int) $event['observed_at_ms']),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }
        BehavioralEvent::query()->insert($rows);

        return response()->json(['accepted' => count($rows)]);
    }
}
