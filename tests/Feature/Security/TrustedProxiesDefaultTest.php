<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Locks the post-v0.8.0 contract that `TRUSTED_PROXIES` defaults to
 * trusting NO proxy, not everything.
 *
 * Background: the previous default was `*`, which let an attacker
 * reaching the origin directly spoof X-Forwarded-* and bypass every
 * IP-keyed signal (BitcoTask webhook allowlist, IpReputationGate,
 * SharedIpSignal, per-IP rate-limit buckets). The new default is
 * empty/null (trust nothing) — operators behind a real proxy MUST
 * set TRUSTED_PROXIES to a CIDR list (or `*` if they know their
 * firewall restricts inbound to the CDN ranges).
 *
 * The test reads bootstrap/app.php source directly because trustProxies
 * resolution runs at app boot and isn't observable post-construction.
 */
class TrustedProxiesDefaultTest extends TestCase
{
    public function test_bootstrap_does_not_default_to_wildcard_trust(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString(
            "env('TRUSTED_PROXIES', '*')",
            $bootstrap,
            "bootstrap/app.php must not default TRUSTED_PROXIES to '*' — that "
            .'would let an attacker spoof X-Forwarded-For from the open internet '
            .'and bypass every IP-keyed signal in the bot-detection stack.'
        );
    }

    public function test_env_example_documents_trusted_proxies(): void
    {
        $envExample = (string) file_get_contents(base_path('.env.example'));

        // The env var name MUST appear in .env.example so a fresh
        // operator copying the example knows the knob exists. Without
        // this, deployments behind a CDN would see http:// links
        // generated for HTTPS pages and not know why.
        $this->assertStringContainsString('TRUSTED_PROXIES', $envExample);
    }
}
