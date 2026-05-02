<?php

namespace Tests\Unit\Shortlinks;

use App\Shortlinks\Providers\GenericShortenerClient;
use App\Shortlinks\Providers\ShortenerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Tests\TestCase;

/**
 * Locks the wire contract for the ouo.io-style shortener family. Adding a new
 * provider that uses the same shape (api token + url + alias + format=json)
 * should pass these tests verbatim.
 */
class GenericShortenerClientTest extends TestCase
{
    public function test_shorten_returns_shortened_url_on_success(): void
    {
        $http = new HttpFactory;
        $http->fake([
            'btcut.io/api*' => $http->response([
                'status' => 'success',
                'message' => '',
                'shortenedUrl' => 'https://btcut.io/abc123',
            ], 200),
        ]);

        $client = new GenericShortenerClient($http, 'btcut', 'https://btcut.io/api', 'TOKEN_xyz');
        $short = $client->shorten('https://example.com/destination?ref=foo', 'myalias');

        $this->assertSame('https://btcut.io/abc123', $short);
        $http->assertSent(function ($request) {
            return $request->url() === 'https://btcut.io/api?api=TOKEN_xyz&url=https%3A%2F%2Fexample.com%2Fdestination%3Fref%3Dfoo&format=json&alias=myalias'
                || (
                    str_starts_with($request->url(), 'https://btcut.io/api?')
                    && str_contains($request->url(), 'api=TOKEN_xyz')
                    && str_contains($request->url(), 'url=')
                    && str_contains($request->url(), 'alias=myalias')
                    && str_contains($request->url(), 'format=json')
                );
        });
    }

    public function test_alias_is_omitted_when_blank(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*' => $http->response(['status' => 'success', 'shortenedUrl' => 'https://btcut.io/no-alias'], 200),
        ]);

        $client = new GenericShortenerClient($http, 'btcut', 'https://btcut.io/api', 'TOKEN_xyz');
        $client->shorten('https://example.com/destination');

        $http->assertSent(fn ($req) => ! str_contains($req->url(), 'alias='));
    }

    public function test_status_error_response_throws_with_provider_message(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*' => $http->response([
                'status' => 'error',
                'message' => 'Invalid URL',
                'shortenedUrl' => '',
            ], 200),
        ]);

        $client = new GenericShortenerClient($http, 'btcut', 'https://btcut.io/api', 'TOKEN_xyz');

        $this->expectException(ShortenerException::class);
        $this->expectExceptionMessage('Invalid URL');
        $client->shorten('not-a-url');
    }

    public function test_http_response_url_is_upgraded_when_api_base_is_https(): void
    {
        // earnow / shortano / shortino return `http://` URLs even though
        // their /api endpoint is HTTPS. Navigating to those from an HTTPS
        // page (ngrok / cloudflare / production) gets silently blocked
        // as mixed content. We upgrade scheme on the way out so the
        // browser can actually follow the redirect.
        $http = new HttpFactory;
        $http->fake([
            '*' => $http->response([
                'status' => 'success',
                'shortenedUrl' => 'http://earnow.online/Yc0wBQA',
            ], 200),
        ]);
        $client = new GenericShortenerClient($http, 'earnow', 'https://earnow.online/api', 'TOKEN');

        $this->assertSame('https://earnow.online/Yc0wBQA', $client->shorten('https://example.com/x'));
    }

    public function test_http_response_url_is_left_alone_when_host_differs_from_api_base(): void
    {
        // Conservative match: never rewrite a cross-domain URL on the way
        // out, even when api_base is HTTPS — that response shape would be
        // an intentional handoff to a different host (rare for shorteners,
        // but the safe default).
        $http = new HttpFactory;
        $http->fake([
            '*' => $http->response([
                'status' => 'success',
                'shortenedUrl' => 'http://other.example/abc',
            ], 200),
        ]);
        $client = new GenericShortenerClient($http, 'earnow', 'https://earnow.online/api', 'TOKEN');

        $this->assertSame('http://other.example/abc', $client->shorten('https://example.com/x'));
    }

    public function test_array_message_in_error_response_does_not_fatal_with_array_to_string(): void
    {
        // Some shortener APIs (cuty in the wild) return validation errors
        // as a nested object: { "message": { "url": ["The url is invalid."] } }.
        // The previous (string) cast on $data['message'] would fatal with
        // "Array to string conversion" → 500 to the user. We now JSON-encode
        // arrays so the operator at least sees the field detail in the toast.
        $http = new HttpFactory;
        $http->fake([
            '*' => $http->response([
                'status' => 'error',
                'message' => ['url' => ['The url field is invalid.']],
                'shortenedUrl' => '',
            ], 200),
        ]);

        $client = new GenericShortenerClient($http, 'cuty', 'https://cuty.io/api', 'TOKEN_xyz');

        try {
            $client->shorten('not-a-url');
            $this->fail('expected ShortenerException');
        } catch (ShortenerException $e) {
            $this->assertStringContainsString('cuty', $e->getMessage());
            $this->assertStringContainsString('url', $e->getMessage());
        }
    }

    public function test_empty_shortened_url_in_response_is_treated_as_error(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*' => $http->response(['status' => 'success', 'shortenedUrl' => ''], 200),
        ]);

        $client = new GenericShortenerClient($http, 'btcut', 'https://btcut.io/api', 'TOKEN_xyz');

        $this->expectException(ShortenerException::class);
        $client->shorten('https://example.com/x');
    }

    public function test_http_failure_throws_with_status_code(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*' => $http->response('Service down', 503),
        ]);

        $client = new GenericShortenerClient($http, 'btcut', 'https://btcut.io/api', 'TOKEN_xyz');

        $this->expectException(ShortenerException::class);
        $this->expectExceptionMessage('HTTP 503');
        $client->shorten('https://example.com/x');
    }

    public function test_connection_exception_is_wrapped_in_shortener_exception(): void
    {
        // A cURL timeout / DNS failure / TCP refused throws
        // Illuminate\Http\Client\ConnectionException at the HTTP layer.
        // We must convert it to the typed ShortenerException so admin
        // surfaces (the Filament Test action) can catch one type
        // instead of bubbling up Guzzle internals as a 500.
        $http = new HttpFactory;
        $http->fake(function (): void {
            throw new ConnectionException('cURL error 28: Connection timed out');
        });

        $client = new GenericShortenerClient($http, 'btcut', 'https://btcut.io/api', 'TOKEN_xyz');

        $this->expectException(ShortenerException::class);
        $this->expectExceptionMessage('Network error reaching `btcut`');
        $client->shorten('https://example.com/x');
    }

    public function test_unconfigured_client_refuses_without_calling_remote(): void
    {
        $http = new HttpFactory;
        $http->fake();

        $client = new GenericShortenerClient($http, 'btcut', 'https://btcut.io/api', '');
        $this->assertFalse($client->isConfigured());

        try {
            $client->shorten('https://example.com/x');
            $this->fail('expected ShortenerException');
        } catch (ShortenerException $e) {
            $this->assertStringContainsString('no API token', $e->getMessage());
        }
        // No HTTP call should have been issued.
        $http->assertNothingSent();
    }

    public function test_empty_url_argument_is_rejected_locally(): void
    {
        $http = new HttpFactory;
        $http->fake();

        $client = new GenericShortenerClient($http, 'btcut', 'https://btcut.io/api', 'TOKEN_xyz');

        $this->expectException(ShortenerException::class);
        $this->expectExceptionMessage('empty URL');
        $client->shorten('');
    }
}
