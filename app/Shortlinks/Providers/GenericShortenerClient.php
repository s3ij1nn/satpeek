<?php

namespace App\Shortlinks\Providers;

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
        $response = $this->http->timeout(10)->get($this->apiBase, $params);

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

        if ($status !== 'success' || $short === '') {
            $msg = is_array($data) && isset($data['message']) && $data['message'] !== ''
                ? (string) $data['message']
                : 'unknown error';
            Log::warning('shortener api error', [
                'provider' => $this->name,
                'status' => $status,
                'message' => $msg,
            ]);
            throw new ShortenerException("`{$this->name}` rejected the request: {$msg}");
        }

        return $short;
    }
}
