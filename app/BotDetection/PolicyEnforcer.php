<?php

namespace App\BotDetection;

use App\Models\User;

class PolicyEnforcer
{
    public function tier(User $user): string
    {
        return $user->botScore?->tier ?? 'trust';
    }

    public function canStartPtcView(User $user): bool
    {
        $tier = $this->tier($user);

        return ! in_array($tier, ['likely_bot', 'banned'], true);
    }

    public function canWithdraw(User $user): bool
    {
        return ! in_array($this->tier($user), ['banned'], true);
    }

    public function withdrawalNeedsReview(User $user): bool
    {
        return in_array($this->tier($user), ['suspect', 'likely_bot'], true);
    }

    public function captchaDifficulty(User $user): int
    {
        return match ($this->tier($user)) {
            'suspect' => 2,
            'likely_bot' => 3,
            default => 1,
        };
    }
}
