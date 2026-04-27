<?php

namespace App\Offerwall;

use App\Offerwall\Contracts\OfferwallAdapter;

class AdapterRegistry
{
    /** @var array<string, OfferwallAdapter> */
    private array $adapters = [];

    public function register(OfferwallAdapter $adapter): void
    {
        $this->adapters[$adapter->name()] = $adapter;
    }

    /** @return array<int, OfferwallAdapter> */
    public function enabled(): array
    {
        $enabled = (array) config('satpeek.offerwalls.enabled', []);
        $out = [];
        foreach ($enabled as $name) {
            if (isset($this->adapters[$name])) {
                $out[] = $this->adapters[$name];
            }
        }

        return $out;
    }

    public function get(string $name): ?OfferwallAdapter
    {
        return $this->adapters[$name] ?? null;
    }
}
