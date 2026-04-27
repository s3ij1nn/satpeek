<?php

namespace Tests\Unit\IpReputation;

use App\IpReputation\Adapters\MaxMindAsnProvider;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use GeoIp2\Model\Asn;
use Tests\TestCase;

/**
 * Locks the MaxMind GeoLite2-ASN local lookup contract:
 *   - missing / unset .mmdb path silently degrades to null (no exception)
 *   - private + loopback + invalid IPs short-circuit before touching the reader
 *   - reader's AddressNotFoundException → null (not propagated)
 *   - happy path returns IpVerdict with asn populated and source='maxmind'
 *   - reader factory is invoked exactly once (lazy + memoised)
 */
class MaxMindAsnProviderTest extends TestCase
{
    public function test_missing_database_file_returns_null_without_throwing(): void
    {
        $provider = new MaxMindAsnProvider('/var/this/path/does/not/exist.mmdb');

        $this->assertNull($provider->lookup('8.8.8.8'));
    }

    public function test_empty_path_returns_null(): void
    {
        $provider = new MaxMindAsnProvider('');

        $this->assertNull($provider->lookup('8.8.8.8'));
    }

    public function test_private_ip_does_not_invoke_reader(): void
    {
        $invoked = 0;
        $factory = function () use (&$invoked): Reader {
            $invoked++;
            $this->fail('Reader factory must not be called for private IPs');
        };
        $provider = new MaxMindAsnProvider('/unused.mmdb', $factory);

        $this->assertNull($provider->lookup('192.168.1.10'));
        $this->assertNull($provider->lookup('127.0.0.1'));
        $this->assertNull($provider->lookup('10.0.0.5'));
        $this->assertNull($provider->lookup('169.254.169.254'));
        $this->assertSame(0, $invoked);
    }

    public function test_invalid_ip_string_returns_null(): void
    {
        $provider = new MaxMindAsnProvider('/unused.mmdb', fn () => $this->fail('reader called for garbage IP'));

        $this->assertNull($provider->lookup('not-an-ip'));
        $this->assertNull($provider->lookup(''));
    }

    public function test_address_not_found_in_db_returns_null(): void
    {
        $reader = $this->createMock(Reader::class);
        $reader->expects($this->once())
            ->method('asn')
            ->with('203.0.113.5')
            ->willThrowException(new AddressNotFoundException('not found'));

        $provider = new MaxMindAsnProvider('/unused.mmdb', fn () => $reader);

        $this->assertNull($provider->lookup('203.0.113.5'));
    }

    public function test_happy_path_returns_verdict_with_asn(): void
    {
        $reader = $this->createMock(Reader::class);
        $reader->method('asn')->willReturn($this->fakeAsnRecord(15169, 'GOOGLE'));

        $provider = new MaxMindAsnProvider('/unused.mmdb', fn () => $reader);

        $verdict = $provider->lookup('8.8.8.8');
        $this->assertNotNull($verdict);
        $this->assertSame('8.8.8.8', $verdict->ip);
        $this->assertSame(15169, $verdict->asn);
        $this->assertSame('maxmind', $verdict->source);
        // GeoLite2-ASN doesn't classify these — must stay false.
        $this->assertFalse($verdict->isProxy);
        $this->assertFalse($verdict->isVpn);
        $this->assertFalse($verdict->isDatacenter);
        $this->assertFalse($verdict->isTor);
        $this->assertSame('GOOGLE', $verdict->raw['asn_org']);
    }

    public function test_reader_is_built_once_and_reused(): void
    {
        $built = 0;
        $reader = $this->createMock(Reader::class);
        $reader->method('asn')->willReturn($this->fakeAsnRecord(15169, 'GOOGLE'));
        $factory = function () use (&$built, $reader): Reader {
            $built++;
            return $reader;
        };
        $provider = new MaxMindAsnProvider('/unused.mmdb', $factory);

        $provider->lookup('8.8.8.8');
        $provider->lookup('1.1.1.1');
        $provider->lookup('9.9.9.9');

        $this->assertSame(1, $built, 'reader must be lazy-built and memoised');
    }

    public function test_reader_init_failure_is_remembered_and_not_retried(): void
    {
        $built = 0;
        $factory = function () use (&$built): Reader {
            $built++;
            throw new \RuntimeException('corrupt file');
        };
        $provider = new MaxMindAsnProvider('/unused.mmdb', $factory);

        $this->assertNull($provider->lookup('8.8.8.8'));
        $this->assertNull($provider->lookup('1.1.1.1'));
        $this->assertSame(1, $built, 'failed reader init must not be retried per-lookup');
    }

    public function test_zero_or_null_asn_in_record_treated_as_missing(): void
    {
        $reader = $this->createMock(Reader::class);
        $reader->method('asn')->willReturn($this->fakeAsnRecord(0, 'unknown'));

        $provider = new MaxMindAsnProvider('/unused.mmdb', fn () => $reader);

        $this->assertNull($provider->lookup('203.0.113.5'));
    }

    /**
     * Build an ASN model record without going through MaxMind's data array
     * shape (which requires `traits` keys we don't care about). We just
     * stub the two properties our provider reads.
     */
    private function fakeAsnRecord(?int $asn, string $org): Asn
    {
        // GeoIp2\Model\Asn's constructor expects a [data => …, locales => …]
        // tuple. Easiest portable construction: build via the documented
        // raw shape so the test stays stable against minor library changes.
        return new Asn([
            'autonomous_system_number' => $asn,
            'autonomous_system_organization' => $org,
            'ip_address' => '0.0.0.0',
            'prefix_len' => 32,
        ]);
    }
}
