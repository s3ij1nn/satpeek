<?php

namespace Tests\Unit\Shortlinks;

use App\Shortlinks\Providers\OuoShortenerClient;
use App\Shortlinks\Providers\ShortenerException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Tests\TestCase;

/**
 * Locks the ouo.io family contract: path-token endpoint
 * (`<api_base>/<token>?s=<url>`), plain-text response, and
 * defence-in-depth against Cloudflare HTML challenge bodies arriving
 * with a 200 status.
 */
class OuoShortenerClientTest extends TestCase
{
    public function test_shorten_returns_url_from_plain_text_body(): void
    {
        $http = new HttpFactory();
        $http->fake([
            'ouo.io/api/*' => $http->response("https://ouo.io/Abc123\n", 200, ['Content-Type' => 'text/plain']),
        ]);

        $client = new OuoShortenerClient($http, 'ouo', 'https://ouo.io/api', 'PUBLISHER_TOKEN');
        $short = $client->shorten('https://example.com/destination?ref=foo');

        $this->assertSame('https://ouo.io/Abc123', $short);
        $http->assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://ouo.io/api/PUBLISHER_TOKEN?')
                && str_contains($request->url(), 's=https%3A%2F%2Fexample.com%2Fdestination%3Fref%3Dfoo');
        });
    }

    public function test_alias_argument_is_ignored_for_path_token_provider(): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*' => $http->response('https://ouo.io/Abc123', 200),
        ]);

        $client = new OuoShortenerClient($http, 'ouo', 'https://ouo.io/api', 'PUBLISHER_TOKEN');
        $client->shorten('https://example.com/x', 'someAlias');

        // The ouo.io public API documents only the `s` parameter — alias is
        // not part of the contract and must not be appended to the request.
        $http->assertSent(fn ($req) => ! str_contains($req->url(), 'alias='));
    }

    public function test_html_challenge_response_with_200_is_treated_as_error(): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*' => $http->response('<!DOCTYPE html><html>Just a moment...</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $client = new OuoShortenerClient($http, 'ouo', 'https://ouo.io/api', 'PUBLISHER_TOKEN');

        $this->expectException(ShortenerException::class);
        $this->expectExceptionMessage('did not return a shortened URL');
        $client->shorten('https://example.com/x');
    }

    public function test_empty_body_with_200_is_treated_as_error(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response('', 200)]);

        $client = new OuoShortenerClient($http, 'ouo', 'https://ouo.io/api', 'PUBLISHER_TOKEN');

        $this->expectException(ShortenerException::class);
        $client->shorten('https://example.com/x');
    }

    public function test_http_error_status_throws_with_status_code(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response('Forbidden', 403)]);

        $client = new OuoShortenerClient($http, 'ouo', 'https://ouo.io/api', 'BAD_TOKEN');

        $this->expectException(ShortenerException::class);
        $this->expectExceptionMessage('HTTP 403');
        $client->shorten('https://example.com/x');
    }

    public function test_unconfigured_client_refuses_without_calling_remote(): void
    {
        $http = new HttpFactory();
        $http->fake();

        $client = new OuoShortenerClient($http, 'ouo', 'https://ouo.io/api', '');
        $this->assertFalse($client->isConfigured());

        try {
            $client->shorten('https://example.com/x');
            $this->fail('expected ShortenerException');
        } catch (ShortenerException $e) {
            $this->assertStringContainsString('no API token', $e->getMessage());
        }
        $http->assertNothingSent();
    }

    public function test_response_body_with_whitespace_is_rejected(): void
    {
        $http = new HttpFactory();
        // Defence against partial HTML bodies that happen to contain a URL.
        $http->fake(['*' => $http->response('Some prose https://ouo.io/Abc123 more prose', 200)]);

        $client = new OuoShortenerClient($http, 'ouo', 'https://ouo.io/api', 'tk');

        $this->expectException(ShortenerException::class);
        $client->shorten('https://example.com/x');
    }
}
