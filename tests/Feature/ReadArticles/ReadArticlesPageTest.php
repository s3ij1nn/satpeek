<?php

declare(strict_types=1);

namespace Tests\Feature\ReadArticles;

use App\Models\User;
use App\Offerwall\AdapterRegistry;
use App\Offerwall\Contracts\OfferDescriptor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Offerwall\FakePerUserAdapter;
use Tests\TestCase;

/**
 * Locks the BitcoTasks-optional contract on the read-articles surface:
 *
 *   - When no per-user adapter is enabled (default until publisher review
 *     ships an API key) the page renders a friendly "no partners" state
 *     instead of 404.
 *   - When a per-user adapter is enabled the page renders the adapter's
 *     offers as plain external links — there is no in-platform completion
 *     flow because the publisher attributes via their server callback.
 */
class ReadArticlesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_no_partner_state_when_no_per_user_adapter_enabled(): void
    {
        config()->set('satpeek.offerwalls.enabled', []);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/read-articles');

        $response->assertOk();
        $response->assertSeeText('No read-article partners connected.');
    }

    public function test_renders_partner_offers_when_adapter_enabled(): void
    {
        config()->set('satpeek.offerwalls.enabled', ['partner']);
        $registry = $this->app->make(AdapterRegistry::class);
        $registry->register(new FakePerUserAdapter('partner', [
            new OfferDescriptor('partner', 'RA-1', 'Read this article', null, 'https://x.test/a', 100, 60),
        ]));

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/read-articles');

        $response->assertOk();
        $response->assertSeeText('Read this article');
        $response->assertSee('https://x.test/a', escape: false);
        // External link goes straight to the publisher — no SatPeek-side
        // hold timer / captcha because attribution is publisher-side.
        $response->assertSee('rel="noopener noreferrer"', escape: false);
    }

    public function test_renders_no_offers_state_when_adapter_returns_empty_list(): void
    {
        config()->set('satpeek.offerwalls.enabled', ['partner']);
        $registry = $this->app->make(AdapterRegistry::class);
        $registry->register(new FakePerUserAdapter('partner', []));

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/read-articles');

        $response->assertOk();
        $response->assertSeeText('No tasks available right now.');
    }

    public function test_route_requires_auth(): void
    {
        $response = $this->get('/read-articles');
        $response->assertRedirect('/login');
    }
}
