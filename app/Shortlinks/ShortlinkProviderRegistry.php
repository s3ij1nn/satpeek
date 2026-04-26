<?php

namespace App\Shortlinks;

use App\Shortlinks\Providers\GenericShortenerClient;
use App\Shortlinks\Providers\ShortenerException;

/**
 * Resolves shortener clients by their config key. Drives the admin UI
 * (Filament action picks among `configuredNames()`) and the Artisan
 * commands so adding a new provider is a one-line config addition.
 */
class ShortlinkProviderRegistry
{
    /** @var array<string, GenericShortenerClient> */
    private array $clients;

    /** @param array<string, GenericShortenerClient> $clients */
    public function __construct(array $clients)
    {
        $this->clients = $clients;
    }

    /** @return array<string, GenericShortenerClient> */
    public function all(): array
    {
        return $this->clients;
    }

    public function get(string $name): GenericShortenerClient
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
