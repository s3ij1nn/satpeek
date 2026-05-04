<?php

namespace App\Http\Controllers;

use App\Services\PublicStatsBuilder;
use Illuminate\View\View;

/**
 * Public landing page. Pulls anonymous-visible platform stats out of
 * {@see PublicStatsBuilder} so the trust signals (lifetime sat paid,
 * active inventory count, last-30d bot rejection rate) on the value
 * strip reflect real numbers instead of marketing claims.
 *
 * The stats service caches for 10 minutes, so this controller costs
 * a single Redis read on the hot path most of the time.
 */
class HomeController extends Controller
{
    public function __invoke(PublicStatsBuilder $stats): View
    {
        return view('home', [
            'stats' => $stats->build(),
        ]);
    }
}
