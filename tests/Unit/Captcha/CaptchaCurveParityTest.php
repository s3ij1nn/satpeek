<?php

declare(strict_types=1);

namespace Tests\Unit\Captcha;

use App\Captcha\TrajectoryTraceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins client ↔ server parity on the captcha curve set.
 *
 * `TrajectoryTraceProvider::issue()` picks uniformly from `CURVES` and scores
 * the submitted trace against `sampleCurve()`. The browser draws the moving
 * target from its own transcription of that formula. When the two disagree,
 * the failure is silent and total: the client renders the target on the
 * straight baseline, the user follows it perfectly, and the server rejects
 * the solve as `shape_mismatch` with no way for the user to recover.
 *
 * That is not hypothetical. `damped_sine`, `growing_sine` and `triangle` were
 * added to `CURVES` without being added to any of the four client copies, so
 * half of all issued challenges rendered as a straight line. Measured against
 * the live verifier with a flawless synthetic solver, 27% of challenges were
 * unsolvable — every captcha surface on the site, including admin login.
 *
 * The four copies are the reason it drifted, and they're why this test reads
 * the views as text: there is no single implementation to unit-test. If the
 * duplication is ever collapsed into one shared helper, replace this with a
 * test of that helper.
 */
class CaptchaCurveParityTest extends TestCase
{
    /**
     * Every Blade that renders the captcha canvas and animates the target.
     *
     * @var array<int, string>
     */
    private const CLIENT_VIEWS = [
        'resources/views/home.blade.php',
        'resources/views/register.blade.php',
        'resources/views/components/trajectory-captcha.blade.php',
        'resources/views/filament/auth/captcha-field.blade.php',
    ];

    public function test_the_curve_set_is_what_the_clients_were_written_against(): void
    {
        // Deliberately a hard-coded list: adding a seventh curve should fail
        // here first and send the author to CLIENT_VIEWS before shipping.
        $this->assertSame(
            ['linear', 'sine', 'lissajous', 'damped_sine', 'growing_sine', 'triangle'],
            $this->curves(),
        );
    }

    public function test_every_client_view_draws_every_curve_the_server_can_issue(): void
    {
        foreach (self::CLIENT_VIEWS as $view) {
            $source = $this->read($view);

            foreach ($this->curves() as $curve) {
                // `linear` is the baseline every implementation falls back to,
                // so it needs no branch of its own.
                if ($curve === 'linear') {
                    continue;
                }

                $this->assertStringContainsString(
                    "curve === '{$curve}'",
                    $source,
                    "{$view} has no branch for the '{$curve}' curve — the target would render on the "
                    .'straight baseline and every solve of that challenge would be rejected as shape_mismatch.',
                );
            }
        }
    }

    /**
     * The envelope terms that distinguish the three curves that regressed.
     * A branch that exists but computes a plain sine is the same outage with
     * extra steps, so pin the shape of each one too.
     */
    public function test_each_client_branch_applies_the_matching_envelope(): void
    {
        $expectations = [
            // lissajous: sine × cos(u·π)
            'lissajous' => 'Math.cos(u * Math.PI)',
            // damped_sine: amplitude decays as (1 - u)
            'damped_sine' => '(1 - u)',
            // triangle: the arcsin(sin(…)) identity, not a smooth sine
            'triangle' => 'Math.asin(Math.sin(',
        ];

        foreach (self::CLIENT_VIEWS as $view) {
            $source = $this->read($view);

            foreach ($expectations as $curve => $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $source,
                    "{$view} branches on '{$curve}' but doesn't apply its envelope ({$needle}).",
                );
            }
        }
    }

    /** @return array<int, string> */
    private function curves(): array
    {
        /** @var array<int, string> $curves */
        $curves = (new ReflectionClass(TrajectoryTraceProvider::class))->getConstant('CURVES');

        return $curves;
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;
        $this->assertFileExists($path, "Captcha client view moved or was deleted: {$relativePath}");

        return (string) file_get_contents($path);
    }
}
