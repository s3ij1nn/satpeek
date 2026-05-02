<?php

namespace App\Shortlinks\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Query-token URL-shortener client (btcut.io / cuty.io / exe.io / shrtfly.com…).
 *
 *   GET <api_base>?api=<token>&url=<long>&alias=<custom>&format=json
 *   → { "status": "success" | "error",
 *       "message": "",
 *       "shortenedUrl": "https://provider.tld/xxx" }
 *
 * The text format returns the URL verbatim on success and an empty body on
 * error — we always request JSON so we can tell success from failure.
 *
 * For the path-token variant (ouo.io: /api/<token>?s=<url>) see
 * {@see OuoShortenerClient}.
 */
class GenericShortenerClient implements ShortenerClient
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

        $params = ['api' => $this->apiToken, 'url' => $url, 'format' => 'json'];
        if ($alias !== null && $alias !== '') {
            $params['alias'] = $alias;
        }

        // 10 s upstream cap is generous — the providers we wrap respond in
        // sub-second under normal load. Anything slower is almost certainly
        // a transient infra issue and the operator should retry.
        // ConnectionException (TCP refused / DNS / read timeout) is
        // converted to ShortenerException so callers see one typed
        // failure instead of the underlying Guzzle exception bubbling
        // up as a 500.
        try {
            $response = $this->http->timeout(10)->get($this->apiBase, $params);
        } catch (ConnectionException $e) {
            Log::warning('shortener network error', [
                'provider' => $this->name,
                'err' => $e->getMessage(),
            ]);
            throw new ShortenerException("Network error reaching `{$this->name}`: ".$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            Log::warning('shortener http error', [
                'provider' => $this->name,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 200),
            ]);
            throw new ShortenerException("HTTP {$response->status()} from `{$this->name}`.");
        }

        $data = $response->json();
        $status = is_array($data) ? ($data['status'] ?? null) : null;
        $short = is_array($data) ? (string) ($data['shortenedUrl'] ?? '') : '';

        $short = $this->matchApiScheme($short);

        if ($status !== 'success' || $short === '') {
            // Some providers return `message` as a nested array (validation
            // errors keyed by field). Flatten so the toast shows something
            // readable and the (string) cast on the next line never fatals
            // with "Array to string conversion".
            $rawMsg = is_array($data) ? ($data['message'] ?? null) : null;
            $msg = match (true) {
                is_string($rawMsg) && $rawMsg !== '' => $rawMsg,
                is_array($rawMsg) && $rawMsg !== [] => json_encode($rawMsg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                default => 'unknown error',
            };
            Log::warning('shortener api error', [
                'provider' => $this->name,
                'status' => $status,
                'message' => $msg,
            ]);
            throw new ShortenerException("`{$this->name}` rejected the request: {$msg}");
        }

        return $short;
    }

    /**
     * Some providers (earnow.online / shortano.link / shortino.link…) return
     * `http://` URLs even when their /api endpoint is HTTPS. Navigating from
     * an HTTPS page to those is silently blocked by browsers as mixed
     * content — the chip click "does nothing". If our api_base is HTTPS
     * AND the returned URL points at the same host with `http://`, upgrade
     * the scheme. Conservative match (same host) so a hypothetical
     * cross-domain redirect intentionally on http isn't rewritten.
     */
    private function matchApiScheme(string $url): string
    {
        if (! str_starts_with($url, 'http://')) {
            return $url;
        }
        $apiScheme = parse_url($this->apiBase, PHP_URL_SCHEME) ?: 'https';
        if ($apiScheme !== 'https') {
            return $url;
        }
        $apiHost = parse_url($this->apiBase, PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);
        if ($apiHost === null || $urlHost === null || strcasecmp($apiHost, $urlHost) !== 0) {
            return $url;
        }

        return 'https://'.substr($url, 7);
    }
}
