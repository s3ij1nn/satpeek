<?php

namespace App\Http\Controllers\Api;

use App\Captcha\ChallengeBuilder;
use App\Captcha\ChallengeVerifier;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaptchaController extends Controller
{
    public function issue(Request $request, ChallengeBuilder $builder): JsonResponse
    {
        $payload = $builder->issue($request);
        return response()->json($payload);
    }

    public function verify(Request $request, ChallengeVerifier $verifier): JsonResponse
    {
        $validated = $request->validate([
            'challengeId' => ['required', 'string', 'max:64'],
            'points' => ['required', 'array', 'min:1'],
            'points.*.x' => ['required', 'numeric'],
            'points.*.y' => ['required', 'numeric'],
            'points.*.t' => ['required', 'numeric'],
            'points.*.pressure' => ['nullable', 'numeric'],
        ]);
        $result = $verifier->verify($request, $validated['challengeId'], $validated['points']);
        return response()->json([
            'passed' => $result->passed,
            'reason' => $result->reason,
            'confidence' => $result->confidence,
        ], $result->passed ? 200 : 422);
    }
}
