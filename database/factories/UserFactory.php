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
        ];
    }
}
