<?php

namespace App\Http\Controllers\Auth;

use App\Captcha\ChallengeVerifier;
use App\Http\Controllers\Controller;
use App\Services\UserIpObserver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public function __construct(
        private readonly ChallengeVerifier $verifier,
        private readonly UserIpObserver $ipObserver,
    ) {}

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
        // IP-only floor: caps total login attempts from one source
        // regardless of which email is targeted, blocking credential-
        // stuffing rotation that would otherwise pass the per-(email,IP)
        // limit by switching usernames. 20/min is loose enough that a
        // shared NAT (campus / mobile) doesn't trip on legitimate
        // simultaneous sign-ins.
        $ipFloorKey = 'login-ip|'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipFloorKey, 20)) {
            $seconds = RateLimiter::availableIn($ipFloorKey);

            return $this->fail($isAjax, 'rate_limited', "Too many attempts. Try again in {$seconds}s.", 429);
        }
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return $this->fail($isAjax, 'rate_limited', "Too many attempts. Try again in {$seconds}s.", 429);
        }
        // Increment the IP floor on EVERY attempt (not just failures) so
        // an attacker can't pad their IP budget with successful logins
        // to other accounts they control. Per-(email,IP) `hit()` calls
        // below stay failure-only since legitimate users do succeed.
        RateLimiter::hit($ipFloorKey, 60);

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

        // Record the IP this user just authenticated from. Returns the
        // count of OTHER users that have signed in from the same IP — a
        // strong duplicate-account signal that the SharedIpSignal feeds
        // into the bot score and the operator can review in admin logs.
        $user = $request->user();
        if ($user) {
            $this->ipObserver->record($user, $request->ip(), source: 'login');
        }

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
