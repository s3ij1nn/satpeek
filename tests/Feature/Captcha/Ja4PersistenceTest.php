<?php

namespace Tests\Feature\Captcha;

use App\Models\CaptchaChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration check: a request bearing a Cloudflare cf-ja4 header lands a
 * normalised value on the captcha_challenges row issued for that request.
 * Locks the middleware → ChallengeBuilder hand-off.
 */
class Ja4PersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloudflare_ja4_persists_on_issued_challenge(): void
    {
        $ja4 = 't13d1517h2_8daaf6152771_b186095e22b6';

        $response = $this->withHeaders([
            'X-SP-Fingerprint' => 'browser-fp-test-123',
            'CF-JA4' => $ja4,
        ])->getJson('/api/captcha/issue');

        $response->assertOk();
        $challengeId = $response->json('challengeId');
        $this->assertNotEmpty($challengeId);

        $stored = CaptchaChallenge::where('challenge_id', $challengeId)->firstOrFail();
        $this->assertSame($ja4, $stored->ja4);
    }

    public function test_request_with_no_upstream_ja4_lands_null(): void
    {
        $response = $this->withHeaders([
            'X-SP-Fingerprint' => 'browser-fp-test-456',
        ])->getJson('/api/captcha/issue');

        $response->assertOk();
        $challengeId = $response->json('challengeId');

        $stored = CaptchaChallenge::where('challenge_id', $challengeId)->firstOrFail();
        $this->assertNull($stored->ja4);
    }

    public function test_garbage_upstream_ja4_is_rejected_before_persistence(): void
    {
        $response = $this->withHeaders([
            'X-SP-Fingerprint' => 'browser-fp-test-789',
            'CF-JA4' => '<script>alert(1)</script>',
        ])->getJson('/api/captcha/issue');

        $response->assertOk();
        $challengeId = $response->json('challengeId');

        $stored = CaptchaChallenge::where('challenge_id', $challengeId)->firstOrFail();
        $this->assertNull($stored->ja4, 'malformed ja4 must not land in the db');
    }
}
