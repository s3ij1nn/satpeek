<?php

namespace App\Filament\Pages\Auth;

use App\Captcha\ChallengeVerifier;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

/**
 * Custom Filament admin login page that gates authentication behind the
 * trajectory captcha. The user drags a token along a server-issued curve;
 * the captured (x, y, t, pressure) trace is validated server-side before
 * Filament runs its standard credentials check.
 */
class Login extends BaseLogin
{
    public ?string $captcha_challenge_id = null;

    public ?string $captcha_points = null;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
                $this->getCaptchaFormComponent(),
            ])
            ->statePath('data');
    }

    protected function getCaptchaFormComponent(): Component
    {
        return View::make('filament.auth.captcha-field')
            ->dehydrated(false)
            ->columnSpanFull();
    }

    public function authenticate(): ?\Filament\Http\Responses\Auth\Contracts\LoginResponse
    {
        // Filament submits the form via Livewire's /livewire/update endpoint,
        // which serialises the component's *public properties* — not raw form
        // fields. The captcha-field Blade pushes its trace into these properties
        // via $wire.set('captcha_challenge_id', …) / $wire.set('captcha_points', …).
        $challengeId = (string) ($this->captcha_challenge_id ?? '');
        $rawPoints = (string) ($this->captcha_points ?? '');
        $points = json_decode($rawPoints, true);

        if ($challengeId === '' || ! is_array($points) || empty($points)) {
            throw ValidationException::withMessages([
                'data.email' => 'Please solve the captcha (drag along the path to the goal) before signing in.',
            ]);
        }

        /** @var ChallengeVerifier $verifier */
        $verifier = app(ChallengeVerifier::class);
        $result = $verifier->verify(request(), $challengeId, $points);

        // Reset so a stale id can't be replayed on the next attempt.
        $this->captcha_challenge_id = null;
        $this->captcha_points = null;

        if (! $result->passed) {
            throw ValidationException::withMessages([
                'data.email' => 'Captcha rejected ('.$result->reason.'). Please solve a new captcha and try again.',
            ]);
        }

        return parent::authenticate();
    }
}
