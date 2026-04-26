<?php

namespace App\BotDetection\Signals;

use App\Models\User;

interface Signal
{
    public function name(): string;

    /**
     * Return a risk score in [0.0, 1.0] for the given user. Higher = more bot-like.
     *
     * @return array{score: float, detail: array<string, mixed>}
     */
    public function evaluate(User $user): array;
}
