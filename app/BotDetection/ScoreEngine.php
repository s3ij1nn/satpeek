<?php

namespace App\BotDetection;

use App\BotDetection\Signals\Signal;
use App\Filament\Resources\UserResource;
use App\Models\BotScore;
use App\Models\BotScoreHistory;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

class ScoreEngine
{
    /**
     * @param  array<int, Signal>  $signals
     */
    public function __construct(private readonly array $signals) {}

    /**
     * Throttled wrapper — skips re-evaluation when the user's BotScore row
     * was updated within the last `$minIntervalSeconds` (defaults to the
     * `bot_score.min_reevaluate_interval_seconds` config, 300 s). Returns
     * the existing row in that case, freshly evaluated otherwise.
     *
     * Lets call sites (UserIpObserver, captcha verify paths) invoke this
     * unconditionally without bombarding the DB on a chatty user. The
     * throttle window is a tradeoff: too low burns query budget, too high
     * lets a hot signal (sudden ban-tier transition) lag a bit. 5 min is
     * tight enough that a login/register burst from a sock-puppet operator
     * still triggers a fresh score on the first hit.
     */
    public function evaluateThrottled(User $user, ?int $minIntervalSeconds = null): BotScore
    {
        $minInterval = $minIntervalSeconds ?? (int) config('satpeek.bot_score.min_reevaluate_interval_seconds', 300);
        $existing = BotScore::query()->where('user_id', $user->id)->first();
        if ($existing && $existing->last_evaluated_at !== null) {
            $sinceLast = (int) abs($existing->last_evaluated_at->diffInSeconds(Carbon::now()));
            if ($sinceLast < $minInterval) {
                return $existing;
            }
        }

        return $this->evaluate($user);
    }

    public function evaluate(User $user): BotScore
    {
        $weights = config('satpeek.bot_score.weights', []);
        $weightSum = array_sum(array_map(fn ($w) => (float) $w, $weights));
        if ($weightSum <= 0) {
            $weightSum = 1.0;
        }

        $score = 0.0;
        $detail = [];
        foreach ($this->signals as $signal) {
            $name = $signal->name();
            $weight = (float) ($weights[$name] ?? 0.0);
            $result = $signal->evaluate($user);
            $detail[$name] = [
                'weight' => $weight,
                'raw' => $result['score'],
                'detail' => $result['detail'],
            ];
            $score += $result['score'] * $weight;
        }
        $score = $score / $weightSum;

        $tier = self::scoreToTier($score);

        // Capture the previous tier BEFORE updateOrCreate overwrites it,
        // so we can detect transitions and notify on escalations.
        $previousTier = BotScore::query()
            ->where('user_id', $user->id)
            ->value('tier');

        $row = BotScore::updateOrCreate(
            ['user_id' => $user->id],
            [
                'score' => $score,
                'tier' => $tier,
                'signals' => $detail,
                'last_evaluated_at' => Carbon::now(),
            ]
        );

        // Append to the trail so the dashboard's tier-trend widget has data
        // to plot. updateOrCreate above only keeps the latest evaluation;
        // without this row we'd lose the transition signal entirely. Wrapped
        // in a try/catch so a fresh DB without the history table (e.g. a
        // Tinker eval mid-migration) doesn't break the live tier write.
        try {
            BotScoreHistory::create([
                'user_id' => $user->id,
                'score' => $score,
                'tier' => $tier,
                'signals' => $detail,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable) {
            // History is best-effort — never let it block the live decision.
        }

        if ($tier === 'banned') {
            // Bypass mass-assignment guard: is_banned must remain admin-only via fillable.
            $user->forceFill(['is_banned' => true, 'ban_reason' => 'bot_score'])->save();
        }

        // Notify operators on tier escalation so a sock-puppet getting
        // bumped from trust→suspect (or worse, →banned) doesn't sit
        // unnoticed in the queue. De-escalations (re-score after a
        // signal cleared) are intentionally silent — the operator
        // doesn't need to be paged every time noise abates.
        if ($previousTier !== null && self::tierRank($tier) > self::tierRank($previousTier)) {
            $this->notifyAdminsOfTierEscalation($user, $previousTier, $tier, $score);
        }

        return $row;
    }

    /**
     * Order trust < suspect < likely_bot < banned. Returns -1 for unknown
     * tiers (defensive — keeps the comparison from false-positively firing
     * on a future renamed tier).
     */
    private static function tierRank(string $tier): int
    {
        return match ($tier) {
            'trust' => 0,
            'suspect' => 1,
            'likely_bot' => 2,
            'banned' => 3,
            default => -1,
        };
    }

    /**
     * Best-effort fan-out to admin Filament inboxes. Silent on failure —
     * a notification dispatch error must never block the live tier write.
     */
    private function notifyAdminsOfTierEscalation(User $user, string $from, string $to, float $score): void
    {
        try {
            $admins = User::query()
                ->where('is_admin', true)
                ->where('id', '!=', $user->id)
                ->get();
            if ($admins->isEmpty()) {
                return;
            }

            $title = match ($to) {
                'banned' => 'User auto-banned',
                'likely_bot' => 'User flagged as likely bot',
                'suspect' => 'User flagged as suspect',
                default => 'Bot tier transition',
            };
            $body = sprintf(
                '%s (#%d) %s → %s · score %.2f',
                $user->username ?? $user->email ?? 'user',
                $user->id,
                $from,
                $to,
                $score,
            );
            $color = match ($to) {
                'banned' => 'danger',
                'likely_bot' => 'danger',
                'suspect' => 'warning',
                default => 'info',
            };

            // Build the action lazily so a unit test that doesn't boot
            // the Filament panel can still exercise this path.
            $url = null;
            try {
                $url = UserResource::getUrl('edit', ['record' => $user->id]);
            } catch (\Throwable) {
                // Filament panel not registered (CLI tinker, some unit
                // tests). The notification still surfaces the user id
                // in the body so the operator can navigate manually.
            }

            $notification = Notification::make()
                ->title($title)
                ->body($body)
                ->color($color)
                ->icon('heroicon-o-shield-exclamation');

            if ($url) {
                $notification->actions([
                    Action::make('view')->label('Open user')->url($url, shouldOpenInNewTab: true),
                ]);
            }

            $notification->sendToDatabase($admins);
        } catch (\Throwable) {
            // Best-effort — never let a notification failure break the
            // tier write or the calling controller.
        }
    }

    public static function scoreToTier(float $score): string
    {
        $cfg = config('satpeek.bot_score');
        if ($score >= ($cfg['ban'] ?? 0.85)) {
            return 'banned';
        }
        if ($score >= ($cfg['likely_bot'] ?? 0.60)) {
            return 'likely_bot';
        }
        if ($score >= ($cfg['suspect'] ?? 0.30)) {
            return 'suspect';
        }

        return 'trust';
    }
}
