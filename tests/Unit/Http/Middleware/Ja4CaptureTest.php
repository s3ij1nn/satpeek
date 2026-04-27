<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\Ja4Capture;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Unit-level contract for the JA4 normaliser.
 *
 *   - precedence: cf-ja4 > x-tls-ja4 > x-ja4 > x-sp-ja4
 *   - format gate rejects anything that isn't shaped like a real JA4 so
 *     a forged header from a curl client can't seed garbage into the
 *     captcha_challenges.ja4 column
 *   - missing upstream header is a no-op (not an error)
 */
class Ja4CaptureTest extends TestCase
{
    private const VALID_JA4 = 't13d1517h2_8daaf6152771_b186095e22b6';

    public function test_passes_through_when_no_upstream_header_present(): void
    {
        $request = Request::create('/x');
        $captured = $this->dispatch($request);

        $this->assertSame('', (string) $captured->headers->get('X-SP-JA4', ''));
    }

    public function test_normalises_cloudflare_header_into_canonical_x_sp_ja4(): void
    {
        $request = Request::create('/x', server: ['HTTP_CF_JA4' => self::VALID_JA4]);
        $captured = $this->dispatch($request);

        $this->assertSame(self::VALID_JA4, $captured->headers->get('X-SP-JA4'));
    }

    public function test_precedence_prefers_cloudflare_over_other_sources(): void
    {
        $other = 't13d1234h2_999999999999_888888888888';
        $request = Request::create('/x', server: [
            'HTTP_X_SP_JA4' => $other,
            'HTTP_X_JA4' => $other,
            'HTTP_X_TLS_JA4' => $other,
            'HTTP_CF_JA4' => self::VALID_JA4, // wins
        ]);
        $captured = $this->dispatch($request);

        $this->assertSame(self::VALID_JA4, $captured->headers->get('X-SP-JA4'));
    }

    public function test_falls_back_through_priority_chain(): void
    {
        $request = Request::create('/x', server: [
            // cf-ja4 + x-tls-ja4 absent → next in line wins
            'HTTP_X_JA4' => self::VALID_JA4,
        ]);
        $captured = $this->dispatch($request);

        $this->assertSame(self::VALID_JA4, $captured->headers->get('X-SP-JA4'));
    }

    public function test_invalid_format_is_dropped_silently(): void
    {
        $request = Request::create('/x', server: ['HTTP_CF_JA4' => 'not-a-ja4']);
        $captured = $this->dispatch($request);

        $this->assertSame('', (string) $captured->headers->get('X-SP-JA4', ''));
    }

    public function test_invalid_format_falls_through_to_next_source(): void
    {
        $request = Request::create('/x', server: [
            'HTTP_CF_JA4' => 'definitely_not_a_ja4_string',
            'HTTP_X_JA4' => self::VALID_JA4,
        ]);
        $captured = $this->dispatch($request);

        $this->assertSame(self::VALID_JA4, $captured->headers->get('X-SP-JA4'));
    }

    public function test_lowercases_digest_for_stable_indexing(): void
    {
        $upper = 'T13D1517H2_8DAAF6152771_B186095E22B6';
        $request = Request::create('/x', server: ['HTTP_CF_JA4' => $upper]);
        $captured = $this->dispatch($request);

        $this->assertSame(strtolower($upper), $captured->headers->get('X-SP-JA4'));
    }

    private function dispatch(Request $request): Request
    {
        (new Ja4Capture)->handle($request, function (Request $r): Response {
            return new Response;
        });

        return $request; // mutated in place by the middleware
    }
}
