<?php

namespace App\Shortlinks\Providers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Path-token URL-shortener client (ouo.io family).
 *
 *   GET <api_base>/<token>?s=<destination>
 *   → 200 with body containing the shortened URL as plain text
 *     (e.g. "https://ouo.io/Abc123\n")
 *
 * Failure modes return either a Cloudflare HTML challenge page, an HTML error
 * page, or a non-2xx status — anything that doesn't parse as a single URL is
 * treated as an upstream error.
 */
class OuoShortenerClient implements ShortenerClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $name,
        private readonly string $apiBase,
        private readonly string $apiToken,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return $this->apiToken !== '';
    }

    /**
     * @throws ShortenerException
     */
    public function shorten(string $url, ?string $alias = null): string
    {
        if (! $this->isConfigured()) {
            throw new ShortenerException("Shortener `{$this->name}` has no API token configured.");
        }
        if ($url === '') {
            throw new ShortenerException('Cannot shorten an empty URL.');
        }

        // Path-token endpoint shape — token is part of the URL, not a query
        // param. ouo.io's `s` is the destination URL; aliases are not part of
        // the documented public API, so they're ignored even when provided.
        $endpoint = rtrim($this->apiBase, '/').'/'.rawurlencode($this->apiToken);
        $response = $this->http
            ->timeout(10)
            ->withHeaders(['Accept' => 'text/plain'])
            ->get($endpoint, ['s' => $url]);

        if (! $response->successful()) {
            Log::warning('shortener http error', [
                'provider' => $this->name,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 200),
            ]);
            throw new ShortenerException("HTTP {$response->status()} from `{$this->name}`.");
        }

        $body = trim((string) $response->body());

        // ouo.io's success response is the URL alone — no JSON, no markup.
        // Reject anything that doesn't look like a plain URL string.
        if ($body === '' || ! self::looksLikeUrl($body)) {
            Log::warning('shortener bad response', [
                'provider' => $this->name,
                'preview' => mb_substr($body, 0, 200),
            ]);
            throw new ShortenerException("`{$this->name}` did not return a shortened URL.");
        }

        return $body;
    }

    /**
     * Tightly conservative URL check: must start with http(s)://, must not
     * contain whitespace, must parse as a URL with a host. Cloudflare
     * challenge pages (HTML) and ouo.io error pages (HTML) both fail here.
     */
    private static function looksLikeUrl(string $candidate): bool
    {
        if (preg_match('/\s/', $candidate)) {
            return false;
        }
        if (! preg_match('#^https?://#i', $candidate)) {
            return false;
        }
        $parts = parse_url($candidate);
        return is_array($parts) && ! empty($parts['host']);
    }
}
