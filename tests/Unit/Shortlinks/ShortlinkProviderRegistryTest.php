<?php

namespace Tests\Unit\Shortlinks;

use App\Shortlinks\Providers\GenericShortenerClient;
use App\Shortlinks\Providers\ShortenerException;
use App\Shortlinks\ShortlinkProviderRegistry;
use Illuminate\Http\Client\Factory as HttpFactory;
use Tests\TestCase;

class ShortlinkProviderRegistryTest extends TestCase
{
    public function test_configured_names_filters_out_clients_without_a_token(): void
    {
        $http = new HttpFactory();
        $registry = new ShortlinkProviderRegistry([
            'btcut' => new GenericShortenerClient($http, 'btcut', 'https://btcut.io/api', 'real_token'),
            'ouo'   => new GenericShortenerClient($http, 'ouo',   'https://ouo.io/api',   ''),
            'cuty'  => new GenericShortenerClient($http, 'cuty',  'https://cuty.io/api',  'another_token'),
        ]);

        $this->assertEqualsCanonicalizing(['btcut', 'cuty'], $registry->configuredNames());
    }

    public function test_get_returns_registered_client(): void
    {
        $http = new HttpFactory();
        $client = new GenericShortenerClient($http, 'btcut', 'https://btcut.io/api', 'tk');
        $registry = new ShortlinkProviderRegistry(['btcut' => $client]);

        $this->assertSame($client, $registry->get('btcut'));
    }

    public function test_get_throws_for_unknown_name(): void
    {
        $registry = new ShortlinkProviderRegistry([]);

        $this->expectException(ShortenerException::class);
        $registry->get('does-not-exist');
    }
}
