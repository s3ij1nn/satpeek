<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\IframeEmbedProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Tests\TestCase;

/**
 * Locks the iframe-embeddability detection rules.
 *
 * Conservative-by-design contract:
 *   - Probe failure (network / 4xx / 5xx) returns embeddable=true
 *     with blocker='probe_failed' so a transient blip never blocks
 *     submission.
 *   - X-Frame-Options with ANY value blocks (DENY / SAMEORIGIN /
 *     ALLOW-FROM all kill cross-origin embedding).
 *   - CSP frame-ancestors blocks only when restrictive — `*` /
 *     `https:` / `http:` are passed as permissive.
 */
class IframeEmbedProbeTest extends TestCase
{
    public function test_clean_response_is_embeddable(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 200)]);

        $result = (new IframeEmbedProbe($http))->probe('https://example.com');

        $this->assertTrue($result['embeddable']);
        $this->assertNull($result['blocker']);
    }

    public function test_x_frame_options_deny_blocks(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 200, ['X-Frame-Options' => 'DENY'])]);

        $result = (new IframeEmbedProbe($http))->probe('https://example.com');

        $this->assertFalse($result['embeddable']);
        $this->assertSame('x_frame_options', $result['blocker']);
        $this->assertStringContainsString('DENY', (string) $result['detail']);
    }

    public function test_x_frame_options_sameorigin_blocks(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 200, ['X-Frame-Options' => 'SAMEORIGIN'])]);

        $result = (new IframeEmbedProbe($http))->probe('https://example.com');

        $this->assertFalse($result['embeddable']);
        $this->assertSame('x_frame_options', $result['blocker']);
    }

    public function test_csp_frame_ancestors_self_blocks(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 200, [
            'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'self'",
        ])]);

        $result = (new IframeEmbedProbe($http))->probe('https://example.com');

        $this->assertFalse($result['embeddable']);
        $this->assertSame('csp_frame_ancestors', $result['blocker']);
    }

    public function test_csp_frame_ancestors_none_blocks(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 200, [
            'Content-Security-Policy' => "frame-ancestors 'none'",
        ])]);

        $result = (new IframeEmbedProbe($http))->probe('https://example.com');

        $this->assertFalse($result['embeddable']);
    }

    public function test_csp_frame_ancestors_wildcard_is_permissive(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 200, [
            'Content-Security-Policy' => 'frame-ancestors *',
        ])]);

        $result = (new IframeEmbedProbe($http))->probe('https://example.com');

        $this->assertTrue($result['embeddable']);
    }

    public function test_csp_frame_ancestors_https_scheme_is_permissive(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 200, [
            'Content-Security-Policy' => 'frame-ancestors https:',
        ])]);

        $result = (new IframeEmbedProbe($http))->probe('https://example.com');

        $this->assertTrue($result['embeddable']);
    }

    public function test_csp_without_frame_ancestors_directive_is_ignored(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 200, [
            'Content-Security-Policy' => "default-src 'self'; img-src *",
        ])]);

        $result = (new IframeEmbedProbe($http))->probe('https://example.com');

        $this->assertTrue($result['embeddable']);
    }

    public function test_connection_exception_returns_probe_failed_but_does_not_block(): void
    {
        $http = new HttpFactory;
        $http->fake(function (): void {
            throw new ConnectionException('cURL error 28: Connection timed out');
        });

        $result = (new IframeEmbedProbe($http))->probe('https://example.com');

        $this->assertTrue($result['embeddable'], 'probe failure must not block submission');
        $this->assertSame('probe_failed', $result['blocker']);
    }

    public function test_5xx_response_returns_probe_failed_but_does_not_block(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('boom', 503)]);

        $result = (new IframeEmbedProbe($http))->probe('https://example.com');

        $this->assertTrue($result['embeddable']);
        $this->assertSame('probe_failed', $result['blocker']);
    }
}
