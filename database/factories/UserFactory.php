<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'username' => $this->faker->unique()->userName(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'faucetpay_email' => $this->faker->safeEmail(),
            'balance_sat' => 0,
            'referral_code' => strtoupper($this->faker->bothify('????????')),
            // Default to a freshly-checked clean adblock state so feature
            // tests for earning routes (PTC start / shortlink start /
            // withdraw) aren't blocked by AdblockGate. Tests that need to
            // exercise the gate explicitly override these fields.
            'adblock_status' => 'clean',
            'adblock_checked_at' => now(),
        ];
    }

    /** Adblock detected — earning routes return 403 adblock_detected. */
    public function withAdblockDetected(): self
    {
        return $this->state(fn () => [
            'adblock_status' => 'detected',
            'adblock_checked_at' => now(),
        ]);
    }

    /** Adblock check expired — earning routes return 403 adblock_check_required. */
    public function withStaleAdblockCheck(): self
    {
        return $this->state(fn () => [
            'adblock_status' => 'clean',
            'adblock_checked_at' => now()->subHour(),
        ]);
    }
}
