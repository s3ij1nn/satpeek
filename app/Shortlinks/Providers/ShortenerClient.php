<?php

namespace App\Shortlinks\Providers;

/**
 * Common contract for the URL-shortener publisher APIs we wrap.
 *
 * Two transport shapes exist in the wild:
 *   - query-token (btcut.io family): ?api=TOKEN&url=URL&format=json → JSON
 *   - path-token (ouo.io family):    /api/TOKEN?s=URL              → text
 *
 * Both implementations share the same observable contract from the caller's
 * perspective: pass a destination URL and an optional alias, get back the
 * shortened URL string, or eat a ShortenerException on any failure.
 */
interface ShortenerClient
{
    /** Stable provider name (matches the registry key + Filament label). */
    public function name(): string;

    /** Whether the operator has actually configured an API token. */
    public function isConfigured(): bool;

    /**
     * @throws ShortenerException
     */
    public function shorten(string $url, ?string $alias = null): string;
}
