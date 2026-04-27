<?php

namespace App\Http\Controllers\Auth;

use App\Captcha\ChallengeVerifier;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public function __construct(private readonly ChallengeVerifier $verifier) {}

    public function show()
    {
        return view('auth.login');
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $isAjax = $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest';

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
            'captcha_challenge_id' => ['required', 'string', 'max:64'],
            'captcha_points' => ['required', 'string', 'max:65536'],
        ]);

        $throttleKey = Str::lower($validated['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return $this->fail($isAjax, 'rate_limited', "Too many attempts. Try again in {$seconds}s.", 429);
        }

        $points = json_decode($validated['captcha_points'], true);
        if (! is_array($points) || empty($points)) {
            return $this->fail($isAjax, 'empty_points', 'Captcha submission was empty. Please drag the path before signing in.');
        }
        $result = $this->verifier->verify($request, $validated['captcha_challenge_id'], $points);
        if (! $result->passed) {
            RateLimiter::hit($throttleKey, 60);

            return $this->fail($isAjax, $result->reason, 'Captcha rejected ('.$result->reason.'). Please try again.');
        }

        if (! Auth::attempt(['email' => $validated['email'], 'password' => $validated['password']], (bool) ($validated['remember'] ?? false))) {
            RateLimiter::hit($throttleKey, 60);

            return $this->fail($isAjax, 'invalid_credentials', 'The credentials we received do not match our records.');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $redirect = $request->session()->pull('url.intended', route('dashboard'));
        if ($isAjax) {
            return response()->json(['status' => 'ok', 'redirect' => $redirect]);
        }

        return redirect()->intended(route('dashboard'));
    }

    private function fail(bool $isAjax, string $reason, string $message, int $code = 422): JsonResponse|RedirectResponse
    {
        if ($isAjax) {
            return response()->json(['status' => 'error', 'reason' => $reason, 'message' => $message], $code);
        }

        return redirect()->route('login')->withErrors(['email' => $message])->withInput(['email' => request('email')]);
    }
}
