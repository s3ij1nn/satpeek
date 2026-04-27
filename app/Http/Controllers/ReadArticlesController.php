<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Offerwall\AdapterRegistry;
use App\Offerwall\Contracts\OfferwallPerUserAdapter;
use App\Offerwall\OfferwallMerge;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Surface for read-article offers from external publishers (BitcoTasks today).
 *
 * Read-article tasks have no in-platform equivalent — admins do not author
 * "read this article" inventory the way they do for PTC ads / shortlinks.
 * The page is therefore a pure pass-through to whichever
 * {@see OfferwallPerUserAdapter} happens to be enabled.
 *
 * BitcoTasks-optional design: when no per-user adapter is enabled (default
 * `OFFERWALLS_ENABLED=` is empty until publisher review approves the
 * application), the page still resolves to a friendly "no offers" state
 * instead of 404. The nav link is hidden in the same condition so the
 * surface is invisible to operators who haven't onboarded a partner.
 */
class ReadArticlesController extends Controller
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly OfferwallMerge $merge,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $offers = $user
            ? $this->merge->fetchReadArticleFor($user, $request->ip() ?? '')
            : [];

        return view('read_articles.index', [
            'offers' => $offers,
            'hasProvider' => self::hasPerUserAdapter($this->registry),
        ]);
    }

    /**
     * True when at least one enabled adapter implements the per-user
     * contract — used by both this controller and the layout nav to decide
     * whether to surface the read-articles entry point.
     */
    public static function hasPerUserAdapter(AdapterRegistry $registry): bool
    {
        foreach ($registry->enabled() as $adapter) {
            if ($adapter instanceof OfferwallPerUserAdapter) {
                return true;
            }
        }

        return false;
    }
}
