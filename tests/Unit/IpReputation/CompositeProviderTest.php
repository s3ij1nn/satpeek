<?php

namespace Tests\Unit\IpReputation;

use App\IpReputation\Adapters\CompositeProvider;
use App\IpReputation\Contracts\IpReputationProvider;
use App\IpReputation\Contracts\IpVerdict;
use Tests\TestCase;

class CompositeProviderTest extends TestCase
{
    public function test_returns_null_when_no_provider_responds(): void
    {
        $composite = new CompositeProvider([
            $this->fakeProvider('a', null),
            $this->fakeProvider('b', null),
        ]);
        $this->assertNull($composite->lookup('1.2.3.4'));
    }

    public function test_single_provider_passes_through(): void
    {
        $verdict = new IpVerdict('1.2.3.4', isProxy: true, isVpn: false, isDatacenter: false, isTor: false, asn: 1234, countryCode: 'US', risk: 80, source: 'a');
        $composite = new CompositeProvider([
            $this->fakeProvider('a', $verdict),
            $this->fakeProvider('b', null),
        ]);

        $result = $composite->lookup('1.2.3.4');
        $this->assertNotNull($result);
        $this->assertSame('a', $result->source);
        $this->assertTrue($result->isProxy);
    }

    public function test_or_combines_block_signals_and_takes_max_risk(): void
    {
        $vA = new IpVerdict('1.2.3.4', isProxy: true, isVpn: false, isDatacenter: false, isTor: false, asn: 1234, countryCode: 'US', risk: 60, source: 'a');
        $vB = new IpVerdict('1.2.3.4', isProxy: false, isVpn: true, isDatacenter: true, isTor: false, asn: 5678, countryCode: 'JP', risk: 85, source: 'b');

        $composite = new CompositeProvider([
            $this->fakeProvider('a', $vA),
            $this->fakeProvider('b', $vB),
        ]);

        $result = $composite->lookup('1.2.3.4');
        $this->assertNotNull($result);
        $this->assertTrue($result->isProxy, 'OR(proxy)');
        $this->assertTrue($result->isVpn, 'OR(vpn)');
        $this->assertTrue($result->isDatacenter, 'OR(datacenter)');
        $this->assertSame(85, $result->risk, 'max risk');
        $this->assertSame('composite', $result->source);
        $this->assertSame(1234, $result->asn, 'first non-null asn wins');
    }

    private function fakeProvider(string $name, ?IpVerdict $verdict): IpReputationProvider
    {
        return new class($name, $verdict) implements IpReputationProvider
        {
            public function __construct(private string $n, private ?IpVerdict $v) {}

            public function name(): string
            {
                return $this->n;
            }

            public function lookup(string $ip): ?IpVerdict
            {
                return $this->v;
            }
        };
    }
}
