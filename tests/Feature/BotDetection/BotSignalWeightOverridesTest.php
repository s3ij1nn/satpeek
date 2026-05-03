<?php

declare(strict_types=1);

namespace Tests\Feature\BotDetection;

use App\Models\BotSignalWeight;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Locks the AppServiceProvider boot-time merge of BotSignalWeight
 * DB rows over the `satpeek.bot_score.weights` config defaults.
 *
 * Three behaviours pinned:
 *   - DB row with is_enabled=true overrides the default weight
 *   - DB row with is_enabled=false zeroes the runtime weight
 *     (signal still evaluates; ScoreEngine just contributes 0)
 *   - Missing DB row keeps the config default unchanged
 */
class BotSignalWeightOverridesTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_row_overrides_default_weight(): void
    {
        config()->set('satpeek.bot_score.weights', [
            'shared_ip' => 0.15,
            'response_time' => 0.20,
        ]);
        BotSignalWeight::create([
            'name' => 'shared_ip',
            'weight' => 0.45,
            'is_enabled' => true,
        ]);

        self::invokeBoot();

        $w = config('satpeek.bot_score.weights');
        $this->assertEqualsWithDelta(0.45, (float) $w['shared_ip'], 0.001);
        $this->assertEqualsWithDelta(0.20, (float) $w['response_time'], 0.001, 'untouched signal stays at default');
    }

    public function test_disabled_row_zeroes_runtime_weight(): void
    {
        config()->set('satpeek.bot_score.weights', [
            'response_time' => 0.20,
            'shared_ip' => 0.15,
        ]);
        BotSignalWeight::create([
            'name' => 'response_time',
            'weight' => 0.20,  // value irrelevant when disabled
            'is_enabled' => false,
        ]);

        self::invokeBoot();

        $w = config('satpeek.bot_score.weights');
        $this->assertSame(0.0, (float) $w['response_time'], 'disabled signal must drop to 0 in the composite');
        $this->assertEqualsWithDelta(0.15, (float) $w['shared_ip'], 0.001);
    }

    public function test_missing_db_row_keeps_default(): void
    {
        config()->set('satpeek.bot_score.weights', [
            'shared_ip' => 0.15,
        ]);
        $this->assertSame(0, BotSignalWeight::count());

        self::invokeBoot();

        $w = config('satpeek.bot_score.weights');
        $this->assertEqualsWithDelta(0.15, (float) $w['shared_ip'], 0.001);
    }

    private static function invokeBoot(): void
    {
        $m = new ReflectionMethod(AppServiceProvider::class, 'applyBotSignalWeightOverrides');
        $m->setAccessible(true);
        $m->invoke(null);
    }
}
