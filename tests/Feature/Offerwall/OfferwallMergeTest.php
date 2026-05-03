<?php

declare(strict_types=1);

namespace Tests\Feature\Offerwall;

use App\Models\User;
use App\Offerwall\AdapterRegistry;
use App\Offerwall\Contracts\CallbackResult;
use App\Offerwall\Contracts\OfferDescriptor;
use App\Offerwall\Contracts\OfferwallAdapter;
use App\Offerwall\Contracts\OfferwallPerUserAdapter;
use App\Offerwall\Contracts\ViewSession;
use App\Offerwall\OfferwallMerge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks the BitcoTasks-optional contract: the merge MUST return [] when no
 * per-user adapter is enabled (default state until publisher review approves
 * the operator's API key) and MUST swallow per-adapter exceptions so one
 * misbehaving partner can't 500 the page.
 */
class OfferwallMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_when_no_offerwalls_enabled(): void
    {
        config()->set('satpeek.offerwalls.enabled', []);
        $registry = new AdapterRegistry;
        $registry->register(new FakePerUserAdapter('partner', [
            new OfferDescriptor('partner', 'X', 'X', null, 'https://x.test', 1, 1),
        ]));

        $merge = new OfferwallMerge($registry);
        $user = User::factory()->create();

        $this->assertSame([], $merge->fetchPtcFor($user, '203.0.113.1'));
        $this->assertSame([], $merge->fetchShortlinkFor($user, '203.0.113.1'));
        $this->assertSame([], $merge->fetchReadArticleFor($user, '203.0.113.1'));
    }

    public function test_skips_zero_arg_only_adapters_silently(): void
    {
        config()->set('satpeek.offerwalls.enabled', ['legacy']);
        $registry = new AdapterRegistry;
        $registry->register(new FakeZeroArgAdapter('legacy'));

        $offers = (new OfferwallMerge($registry))
            ->fetchPtcFor(User::factory()->create(), '203.0.113.1');

        $this->assertSame([], $offers);
    }

    public function test_merges_offers_from_per_user_adapter_when_enabled(): void
    {
        config()->set('satpeek.offerwalls.enabled', ['partner']);
        $registry = new AdapterRegistry;
        $registry->register(new FakePerUserAdapter('partner', [
            new OfferDescriptor('partner', 'A', 'Article A', null, 'https://x.test/a', 100, 30),
            new OfferDescriptor('partner', 'B', 'Article B', null, 'https://x.test/b', 200, 60),
        ]));

        $offers = (new OfferwallMerge($registry))
            ->fetchReadArticleFor(User::factory()->create(), '203.0.113.1');

        $this->assertCount(2, $offers);
        $this->assertSame('A', $offers[0]->externalId);
        $this->assertSame('B', $offers[1]->externalId);
    }

    public function test_thrown_adapter_is_logged_and_skipped_not_propagated(): void
    {
        config()->set('satpeek.offerwalls.enabled', ['broken', 'partner']);
        $registry = new AdapterRegistry;
        $registry->register(new ThrowingPerUserAdapter('broken'));
        $registry->register(new FakePerUserAdapter('partner', [
            new OfferDescriptor('partner', 'OK', 'OK', null, 'https://x.test', 5, 30),
        ]));

        Log::spy();

        $offers = (new OfferwallMerge($registry))
            ->fetchPtcFor(User::factory()->create(), '203.0.113.1');

        // The healthy adapter's offers still come through.
        $this->assertCount(1, $offers);
        $this->assertSame('OK', $offers[0]->externalId);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg): bool => str_contains($msg, 'offerwall merge: adapter threw'))
            ->once();
    }
}

/** @internal test helper */
final class FakePerUserAdapter implements OfferwallAdapter, OfferwallPerUserAdapter
{
    /** @param array<int, OfferDescriptor> $offers */
    public function __construct(private readonly string $name, private readonly array $offers) {}

    public function name(): string
    {
        return $this->name;
    }

    public function fetchPtcOffers(): array
    {
        return [];
    }

    public function startView(User $user, OfferDescriptor $offer): ViewSession
    {
        throw new LogicException('not used');
    }

    public function fetchPtcOffersFor(User $user, string $ip): array
    {
        return $this->offers;
    }

    public function fetchShortlinkOffersFor(User $user, string $ip): array
    {
        return $this->offers;
    }

    public function fetchReadArticleOffersFor(User $user, string $ip): array
    {
        return $this->offers;
    }

    public function verifyCallback(Request $request): ?CallbackResult
    {
        return null;
    }
}

/** @internal test helper */
final class FakeZeroArgAdapter implements OfferwallAdapter
{
    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function fetchPtcOffers(): array
    {
        return [];
    }

    public function startView(User $user, OfferDescriptor $offer): ViewSession
    {
        throw new LogicException('not used');
    }

    public function verifyCallback(Request $request): ?CallbackResult
    {
        return null;
    }
}

/** @internal test helper */
final class ThrowingPerUserAdapter implements OfferwallAdapter, OfferwallPerUserAdapter
{
    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function fetchPtcOffers(): array
    {
        return [];
    }

    public function startView(User $user, OfferDescriptor $offer): ViewSession
    {
        throw new LogicException('not used');
    }

    public function fetchPtcOffersFor(User $user, string $ip): array
    {
        throw new RuntimeException('adapter blew up');
    }

    public function fetchShortlinkOffersFor(User $user, string $ip): array
    {
        throw new RuntimeException('adapter blew up');
    }

    public function fetchReadArticleOffersFor(User $user, string $ip): array
    {
        throw new RuntimeException('adapter blew up');
    }

    public function verifyCallback(Request $request): ?CallbackResult
    {
        return null;
    }
}
