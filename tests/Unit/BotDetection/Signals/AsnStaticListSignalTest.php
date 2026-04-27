<?php

namespace Tests\Unit\BotDetection\Signals;

use App\BotDetection\Signals\AsnStaticListSignal;
use App\IpReputation\Contracts\IpReputationProvider;
use App\IpReputation\Contracts\IpVerdict;
use App\Models\CaptchaChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AsnStaticListSignalTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_static_list_returns_zero_with_explicit_reason(): void
    {
        config()->set('satpeek.datacenter_asns', []);
        $user = User::factory()->create();
        $this->seedChallenge($user, '203.0.113.10');

        $signal = new AsnStaticListSignal(new ProviderStub([]));
        $result = $signal->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('no_static_list', $result['detail']['reason']);
    }

    public function test_user_with_no_recent_ips_scores_zero(): void
    {
        config()->set('satpeek.datacenter_asns', ['16509', '14618']);
        $user = User::factory()->create();
        // No challenges seeded — signal must not blow up.

        $signal = new AsnStaticListSignal(new ProviderStub([]));
        $result = $signal->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(0, $result['detail']['samples']);
    }

    public function test_matching_asn_scores_proportionally(): void
    {
        // AS16509 = AWS, AS14618 = AWS-EC2 — canonical datacenters.
        config()->set('satpeek.datacenter_asns', ['16509', '14618', '15169']);

        $user = User::factory()->create();
        $this->seedChallenge($user, '203.0.113.10');
        $this->seedChallenge($user, '203.0.113.20');
        $this->seedChallenge($user, '198.51.100.5');

        $provider = new ProviderStub([
            '203.0.113.10' => 16509,   // hit (AWS)
            '203.0.113.20' => 16509,   // hit (AWS, same)
            '198.51.100.5' => 7922,    // miss (Comcast residential)
        ]);
        $signal = new AsnStaticListSignal($provider);
        $result = $signal->evaluate($user);

        // 2 hits / 3 sampled — even one block weighs the user.
        $this->assertEqualsWithDelta(0.667, $result['score'], 0.01);
        $this->assertSame(2, $result['detail']['hits']);
        $this->assertSame(3, $result['detail']['sampled']);
    }

    public function test_no_provider_response_does_not_penalise(): void
    {
        config()->set('satpeek.datacenter_asns', ['16509']);
        $user = User::factory()->create();
        $this->seedChallenge($user, '203.0.113.10');
        $this->seedChallenge($user, '203.0.113.20');

        // Provider returns null for every IP — no signal available.
        $signal = new AsnStaticListSignal(new ProviderStub([]));
        $result = $signal->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('no_provider_response', $result['detail']['reason']);
    }

    public function test_verdict_without_asn_is_skipped_silently(): void
    {
        config()->set('satpeek.datacenter_asns', ['16509']);
        $user = User::factory()->create();
        $this->seedChallenge($user, '203.0.113.10');

        // Provider returns a verdict but with asn=null (some providers do
        // this for residential IPs). The signal must treat it as "no ASN
        // data" rather than counting it as a sampled-but-missed.
        $signal = new AsnStaticListSignal(new ProviderStub(['203.0.113.10' => null]));
        $result = $signal->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('no_provider_response', $result['detail']['reason']);
    }

    public function test_non_matching_asn_scores_zero_but_increments_samples(): void
    {
        config()->set('satpeek.datacenter_asns', ['16509']);
        $user = User::factory()->create();
        $this->seedChallenge($user, '198.51.100.5');

        $signal = new AsnStaticListSignal(new ProviderStub(['198.51.100.5' => 7922]));
        $result = $signal->evaluate($user);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(1, $result['detail']['sampled']);
        $this->assertSame(0, $result['detail']['hits']);
    }

    public function test_garbage_config_entries_are_filtered_out(): void
    {
        // Operator pasted with an "AS" prefix and stray whitespace.
        config()->set('satpeek.datacenter_asns', ['AS16509', ' 14618 ', '', 'banana']);
        $user = User::factory()->create();
        $this->seedChallenge($user, '203.0.113.10');

        $signal = new AsnStaticListSignal(new ProviderStub(['203.0.113.10' => 16509]));
        $result = $signal->evaluate($user);

        $this->assertSame(1.0, $result['score']);
        $this->assertSame(2, $result['detail']['list_size']);
    }

    private function seedChallenge(User $user, string $ip): CaptchaChallenge
    {
        return CaptchaChallenge::create([
            'challenge_id' => 'cc_'.uniqid(),
            'user_id' => $user->id,
            'session_id' => 'test',
            'provider' => 'trajectory_trace',
            'seed' => 'seed',
            'expected_shape' => [['x' => 0, 'y' => 0, 't' => 0]],
            'fingerprint_hash' => null,
            'client_ip' => $ip,
            'ja4' => null,
            'user_agent' => 'phpunit',
            'status' => 'verified',
            'issued_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinute(),
        ]);
    }
}

/**
 * Tiny IpReputationProvider stub for unit-testing the signal in isolation
 * without booting the live composite stack. Maps IP → ASN; null = the
 * provider returned no verdict for that IP at all.
 */
class ProviderStub implements IpReputationProvider
{
    /** @param array<string, int|null> $byIp */
    public function __construct(private array $byIp) {}

    public function name(): string { return 'stub'; }

    public function lookup(string $ip): ?IpVerdict
    {
        if (! array_key_exists($ip, $this->byIp)) {
            return null;
        }
        $asn = $this->byIp[$ip];
        if ($asn === null) {
            // Provider returned a verdict but couldn't determine ASN.
            return new IpVerdict($ip, false, false, false, false, null, null, 0, 'stub');
        }
        return new IpVerdict($ip, false, false, false, false, $asn, null, 0, 'stub');
    }
}
