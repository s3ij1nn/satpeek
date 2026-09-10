<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Concerns\SolvesTrajectoryCaptcha;
use Tests\TestCase;

/**
 * Pins the trajectory captcha on the Filament admin login page.
 *
 * This is the one captcha surface that runs through Livewire rather than a
 * plain form POST: `resources/views/filament/auth/captcha-field.blade.php`
 * pushes the trace into the component with
 * `$wire.set('captcha_points', …, false)` from Alpine, and
 * `Login::authenticate()` reads it back off the public property before
 * delegating to Filament's credential check.
 *
 * That handoff has no HTTP contract to assert against — which is how it
 * stayed uncovered while the rest of the captcha surface (register, resend)
 * got tests. The gap matters on dependency upgrades: if a Livewire major
 * changes property hydration or the `$wire.set` signature, admin login fails
 * closed with "Please solve the captcha…" and nothing else in the suite
 * notices. See PR #24 (Filament 4 → 5, which pulls Livewire 3 → 4).
 *
 * So the assertions here are deliberately about the *wire*, not just the
 * verifier: that the two properties are writable from the client, that the
 * component reads them, and that the Blade still emits the `$wire.set` calls
 * that populate them.
 */
class AdminLoginCaptchaTest extends TestCase
{
    use RefreshDatabase;
    use SolvesTrajectoryCaptcha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applyCaptchaTestConfig();
    }

    public function test_login_page_renders_the_captcha_canvas_and_wire_bindings(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertSee('Trajectory captcha');
        $response->assertSee('x-ref="canvas"', false);
        // The Alpine → Livewire handoff. If a Filament/Livewire upgrade drops
        // the schema View component or renames $wire, this is what breaks
        // first — and it breaks silently in the browser otherwise.
        $response->assertSee("\$wire.set('captcha_challenge_id'", false);
        $response->assertSee("\$wire.set('captcha_points'", false);
    }

    public function test_authenticate_without_a_captcha_solve_is_rejected(): void
    {
        $admin = $this->admin();

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password')
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
    }

    public function test_captcha_properties_are_writable_from_the_client(): void
    {
        [$challenge, $shape] = $this->seedChallenge();

        // Neither property may be #[Locked]: the Blade sets both over the
        // wire, so a lock would make the login page unusable. Livewire throws
        // CannotUpdateLockedPropertyException from set() if that regresses.
        Livewire::test(Login::class)
            ->set('captcha_challenge_id', $challenge->challenge_id)
            ->set('captcha_points', json_encode($this->humanoidTrace($shape)))
            ->assertSet('captcha_challenge_id', $challenge->challenge_id);
    }

    public function test_authenticate_with_synthetic_uniform_dt_trace_is_rejected(): void
    {
        $admin = $this->admin();
        [$challenge, $shape] = $this->seedChallenge();

        // The canonical headless-Playwright pattern: perfect 16 ms Δt, exact
        // shape replay, zero pressure. Fails dt_jitter + jerk_entropy.
        $points = [];
        for ($i = 0; $i < 80; $i++) {
            $idx = (int) round(($i / 79) * (count($shape) - 1));
            $points[] = ['x' => $shape[$idx]['x'], 'y' => $shape[$idx]['y'], 't' => round(16.0 * $i, 2), 'pressure' => 0];
        }

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password')
            ->set('captcha_challenge_id', $challenge->challenge_id)
            ->set('captcha_points', json_encode($points))
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
        $this->assertSame('rejected', $challenge->fresh()->status);
    }

    public function test_authenticate_with_humanoid_trace_signs_the_admin_in(): void
    {
        $admin = $this->admin();
        [$challenge, $shape] = $this->seedChallenge();

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password')
            ->set('captcha_challenge_id', $challenge->challenge_id)
            ->set('captcha_points', json_encode($this->humanoidTrace($shape)))
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
        $this->assertSame('verified', $challenge->fresh()->status);
    }

    public function test_a_solved_challenge_cannot_be_replayed_on_a_second_attempt(): void
    {
        $admin = $this->admin();
        [$challenge, $shape] = $this->seedChallenge();
        $points = json_encode($this->humanoidTrace($shape));

        // authenticate() nulls both properties after a verify, so a client
        // that re-submits has to re-set them — and the challenge row is
        // already 'verified' by then.
        $component = Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password')
            ->set('captcha_challenge_id', $challenge->challenge_id)
            ->set('captcha_points', $points)
            ->call('authenticate')
            ->assertSet('captcha_challenge_id', null)
            ->assertSet('captcha_points', null);

        Auth::logout();

        $component
            ->set('captcha_challenge_id', $challenge->challenge_id)
            ->set('captcha_points', $points)
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
    }

    public function test_unknown_challenge_id_is_rejected(): void
    {
        $admin = $this->admin();
        [, $shape] = $this->seedChallenge();

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password')
            ->set('captcha_challenge_id', 'cc_not_a_real_challenge')
            ->set('captcha_points', json_encode($this->humanoidTrace($shape)))
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
    }

    public function test_a_passing_captcha_does_not_excuse_wrong_credentials(): void
    {
        $admin = $this->admin();
        [$challenge, $shape] = $this->seedChallenge();

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'not-the-password')
            ->set('captcha_challenge_id', $challenge->challenge_id)
            ->set('captcha_points', json_encode($this->humanoidTrace($shape)))
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
        // The captcha itself passed — the rejection came from Filament's
        // credential check, which runs after ours.
        $this->assertSame('verified', $challenge->fresh()->status);
    }

    public function test_non_admin_with_a_passing_captcha_cannot_enter_the_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        [$challenge, $shape] = $this->seedChallenge();

        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'password')
            ->set('captcha_challenge_id', $challenge->challenge_id)
            ->set('captcha_points', json_encode($this->humanoidTrace($shape)))
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
    }

    public function test_expired_challenge_is_rejected(): void
    {
        $admin = $this->admin();
        [$challenge, $shape] = $this->seedChallenge();
        $challenge->update(['expires_at' => now()->subSecond()]);

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password')
            ->set('captcha_challenge_id', $challenge->challenge_id)
            ->set('captcha_points', json_encode($this->humanoidTrace($shape)))
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
        $this->assertSame('expired', $challenge->fresh()->status);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }
}
