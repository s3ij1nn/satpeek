<?php

namespace App\Http\Controllers\Auth;

use App\Captcha\ChallengeVerifier;
use App\Http\Controllers\Controller;
use App\Mail\WelcomeEmail;
use App\Models\Referral;
use App\Models\User;
use App\Services\UserIpObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class RegisterController extends Controller
{
    public function __construct(
        private readonly ChallengeVerifier $verifier,
        private readonly UserIpObserver $ipObserver,
    ) {}

    public function show(Request $request)
    {
        return view('auth.register', [
            'referralCode' => $request->query('ref'),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $isAjax = $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest';

        if ($request->filled('website')) {
            return $this->ok($isAjax, 'Thanks!');
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('users', 'username')],
            'email' => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            'faucetpay_email' => ['nullable', 'email', 'max:200'],
            'referral_code' => ['nullable', 'string', 'max:16'],
            'agree' => ['accepted'],
            'captcha_challenge_id' => ['required', 'string', 'max:64'],
            'captcha_points' => ['required', 'string', 'max:65536'],
        ]);

        $points = json_decode($validated['captcha_points'], true);
        if (! is_array($points) || empty($points)) {
            return $this->fail($isAjax, 'empty_points', 'Captcha submission was empty. Please drag along the path and try again.');
        }
        $result = $this->verifier->verify($request, $validated['captcha_challenge_id'], $points);
        if (! $result->passed) {
            return $this->fail($isAjax, $result->reason, 'Captcha rejected ('.$result->reason.'). Please trace the moving target into the goal and try again.');
        }

        $referrer = null;
        if (! empty($validated['referral_code'])) {
            $referrer = User::where('referral_code', strtoupper($validated['referral_code']))->first();
        }

        $user = User::create([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'faucetpay_email' => $validated['faucetpay_email'] ?? null,
            'referrer_id' => $referrer?->id,
            'registration_ip' => $request->ip(),
        ]);

        if ($referrer) {
            Referral::firstOrCreate(['referrer_id' => $referrer->id, 'referred_id' => $user->id]);
        }

        // First IP observation for this user. Returns the count of OTHER
        // users that registered / signed in from the same IP — surfaces
        // sock-puppet account creation that cookie-based dedup misses
        // (incognito window, freshly-cleared cookies).
        $this->ipObserver->record($user, $request->ip(), source: 'register');

        event(new Registered($user));

        try {
            Mail::to($user->email)->queue(new WelcomeEmail($user));
        } catch (\Throwable $e) {
            Log::warning('welcome mail queue failed', ['user_id' => $user->id, 'err' => $e->getMessage()]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        $message = "Welcome aboard. We've sent a verification link to {$user->email}. Please open it to unlock withdrawals.";
        if ($isAjax) {
            return response()->json([
                'status' => 'ok',
                'redirect' => route('dashboard'),
                'message' => $message,
            ]);
        }

        return redirect()->route('dashboard')->with('status', $message);
    }

    private function ok(bool $isAjax, string $message): JsonResponse|RedirectResponse
    {
        if ($isAjax) {
            return response()->json(['status' => 'ok', 'message' => $message]);
        }

        return redirect()->route('register')->with('status', $message);
    }

    private function fail(bool $isAjax, string $reason, string $message): JsonResponse|RedirectResponse
    {
        if ($isAjax) {
            return response()->json(['status' => 'error', 'reason' => $reason, 'message' => $message], 422);
        }

        return redirect()->route('register')->withErrors(['captcha' => $message])->withInput();
    }
}
