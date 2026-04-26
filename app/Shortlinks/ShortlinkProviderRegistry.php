<?php

namespace App\Shortlinks;

use App\Shortlinks\Providers\ShortenerClient;
use App\Shortlinks\Providers\ShortenerException;

/**
 * Resolves shortener clients by their config key. Drives the admin UI
 * (Filament action picks among `configuredNames()`) and the Artisan
 * commands so adding a new provider is a one-line config addition.
 *
 * The registry is transport-agnostic: it holds anything that satisfies
 * the {@see ShortenerClient} contract — both query-token and path-token
 * implementations live in here side-by-side.
 */
class ShortlinkProviderRegistry
{
    /** @var array<string, ShortenerClient> */
    private array $clients;

    /** @param array<string, ShortenerClient> $clients */
    public function __construct(array $clients)
    {
        $this->clients = $clients;
    }

    /** @return array<string, ShortenerClient> */
    public function all(): array
    {
        return $this->clients;
    }

    public function get(string $name): ShortenerClient
    {
        if (! isset($this->clients[$name])) {
            throw new ShortenerException("No shortener provider registered under name `{$name}`.");
        }
        return $this->clients[$name];
    }

    /** @return array<int, string> Names of providers whose api token is set. */
    public function configuredNames(): array
    {
        $names = [];
        foreach ($this->clients as $name => $client) {
            if ($client->isConfigured()) {
                $names[] = $name;
            }
        }
        return $names;
    }
}
