<?php

namespace App\BotDetection;

use App\Models\User;

class PolicyEnforcer
{
    public function tier(User $user): string
    {
        // Larastan resolves $user->botScore through the HasOne method's
        // declared return type as non-null, even with @property-read
        // BotScore|null on the User model. The runtime nullability is
        // real (no botScore row exists until ScoreEngine has evaluated
        // the user at least once), so we keep the `?->` guard and the
        // `?? 'trust'` default. The ignore is scoped to one line so any
        // future false-positive elsewhere still surfaces.
        // @phpstan-ignore-next-line nullsafe.neverNull
        return $user->botScore?->tier ?? 'trust';
    }

    /**
     * Tier gate for ALL earning surfaces (PTC views, shortlink clicks,
     * internal article reads). Same rule across surfaces: `likely_bot`
     * and `banned` tiers cannot start an earning session.
     */
    public function canStartEarningSession(User $user): bool
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
