<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#07090f">
    <title>{{ $title ?? config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

    <style>
        :root {
            color-scheme: dark;

            /* surfaces */
            --bg-canvas: #07090f;
            --bg-panel: #0c1018;
            --bg-elev: #11161f;
            --bg-elev-2: #161d29;

            /* borders */
            --border-faint: #131a25;
            --border-subtle: #1d2634;
            --border-strong: #2a3647;

            /* text — values audited for WCAG AA (4.5:1) on --bg-canvas
             * (#07090f). Tertiary used to be #6b7686 which was 4.18:1
             * (sub-AA at body sizes); quaternary was #4a5260 (~2.4:1,
             * fail at any size). Bumped to the values below. */
            --text-primary: #f4f6f9;
            --text-secondary: #aab4c2;
            --text-tertiary: #8a96a8;
            --text-quaternary: #707b8a;

            /* accents */
            --amber: #f59e0b;
            --amber-soft: #fcd34d;
            --amber-glow: rgba(245, 158, 11, 0.18);
            --cyan: #67e8f9;
            --cyan-soft: #a5f3fc;
            --rose: #fb7185;
            --mint: #34d399;

            /* type scale (fluid) */
            --display-xl: clamp(3.25rem, 1.5rem + 6vw, 6.5rem);
            --display-lg: clamp(2.25rem, 1.25rem + 3.5vw, 4rem);
            --display-md: clamp(1.625rem, 1rem + 2vw, 2.5rem);
            --text-lg: clamp(1.0625rem, 1rem + 0.3vw, 1.25rem);
            --text-base: 0.9375rem;
            --text-sm: 0.8125rem;
            --text-xs: 0.6875rem;

            /* rhythm */
            --space-section: clamp(4rem, 2rem + 6vw, 8rem);
            --space-block: clamp(2rem, 1rem + 2vw, 3.5rem);

            /* motion */
            --ease-out-expo: cubic-bezier(0.16, 1, 0.3, 1);
            --ease-in-out-quint: cubic-bezier(0.83, 0, 0.17, 1);
            --dur-fast: 160ms;
            --dur-normal: 320ms;
            --dur-slow: 720ms;

            /* radii */
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 18px;

            /* type families */
            --font-display: 'Instrument Serif', 'Iowan Old Style', 'Apple Garamond', Georgia, serif;
            --font-sans: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            --font-mono: 'JetBrains Mono', ui-monospace, 'SF Mono', Menlo, monospace;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            background: var(--bg-canvas);
            color: var(--text-primary);
            font-family: var(--font-sans);
            font-size: var(--text-base);
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
            font-feature-settings: 'ss01', 'cv11';
        }

        ::selection { background: var(--amber-glow); color: var(--amber-soft); }

        a { color: inherit; text-decoration: none; transition: color var(--dur-fast) var(--ease-out-expo); }
        a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible, select:focus-visible {
            outline: 2px solid var(--amber);
            outline-offset: 3px;
            border-radius: 4px;
        }
        /* Skip link — keyboard / SR users land at <main> directly. */
        .skip-link {
            position: absolute; left: 1rem; top: -40px;
            background: var(--amber); color: #1a0e00;
            padding: 0.5rem 0.875rem;
            border-radius: var(--radius-md);
            font-weight: 500;
            z-index: 100;
            transition: top var(--dur-fast) var(--ease-out-expo);
        }
        .skip-link:focus { top: 0.75rem; }
        /* Sticky-header offset for tab-stops landing under the header. */
        h1, h2, h3, [tabindex], input, button, a, label { scroll-margin-top: 5rem; }

        /* — Header — */
        .site-header {
            position: sticky; top: 0; z-index: 50;
            backdrop-filter: blur(14px) saturate(140%);
            -webkit-backdrop-filter: blur(14px) saturate(140%);
            background: color-mix(in oklab, var(--bg-canvas) 78%, transparent);
            border-bottom: 1px solid var(--border-faint);
        }
        .site-header__inner {
            max-width: 76rem; margin: 0 auto;
            padding: 1rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
            gap: 2rem;
        }
        .brand {
            display: inline-flex; align-items: center; gap: 0.625rem;
            font-family: var(--font-display);
            font-size: 1.5rem; font-style: italic;
            letter-spacing: -0.01em;
            color: var(--text-primary);
        }
        .brand__mark {
            width: 22px; height: 22px;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, var(--amber-soft), var(--amber) 70%, #b45309 100%);
            box-shadow: 0 0 0 1px rgba(0,0,0,0.4) inset, 0 0 24px var(--amber-glow);
        }
        .nav { display: flex; align-items: center; gap: 1.5rem; font-size: var(--text-sm); }
        .nav a { color: var(--text-secondary); }
        .nav a:hover { color: var(--text-primary); }
        .nav .balance {
            font-family: var(--font-mono);
            font-size: var(--text-xs);
            color: var(--amber-soft);
            padding: 0.25rem 0.5rem;
            background: var(--amber-glow);
            border-radius: var(--radius-sm);
            letter-spacing: 0.04em;
        }
        .btn-ghost { background: none; border: 0; color: inherit; cursor: pointer; padding: 0; font: inherit; }
        .nav__cta {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.45rem 0.9rem;
            border-radius: var(--radius-md);
            background: var(--amber);
            color: #1a0e00 !important;
            font-weight: 500;
            box-shadow: 0 1px 0 rgba(255,255,255,0.18) inset, 0 4px 14px var(--amber-glow);
            transition: transform var(--dur-fast) var(--ease-out-expo), background var(--dur-fast) var(--ease-out-expo);
        }
        .nav__cta:hover { background: var(--amber-soft); color: #1a0e00 !important; transform: translateY(-1px); }
        @media (max-width: 720px) {
            .nav > a:not(.nav__cta) { display: none; }
        }

        /* — Layout — */
        main { min-height: 70vh; }
        .container { max-width: 76rem; margin: 0 auto; padding: 0 1.5rem; }

        /* — Footer — */
        .site-footer {
            border-top: 1px solid var(--border-faint);
            margin-top: var(--space-section);
            padding: 3rem 1.5rem 5rem;
            color: var(--text-tertiary);
            font-size: var(--text-sm);
        }
        .site-footer__inner {
            max-width: 76rem; margin: 0 auto;
            display: flex; flex-wrap: wrap; gap: 2rem;
            justify-content: space-between; align-items: flex-start;
        }
        .site-footer__col h3 {
            font-family: var(--font-mono);
            font-size: var(--text-xs);
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.12em;
            margin: 0 0 0.75rem;
        }
        .site-footer__col ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 0.5rem; }
        .site-footer__legal { font-family: var(--font-mono); font-size: var(--text-xs); }

        @media (prefers-reduced-motion: reduce) {
            *, ::before, ::after {
                animation-duration: 0.001ms !important;
                transition-duration: 0.001ms !important;
                scroll-behavior: auto !important;
            }
        }
    </style>

    @stack('head')
</head>
<body>

<a class="skip-link" href="#main">Skip to main content</a>

<header class="site-header">
    <div class="site-header__inner">
        <a href="{{ route('home') }}" class="brand">
            <span class="brand__mark" aria-hidden="true"></span>
            <span>SatPeek</span>
        </a>
        <nav class="nav" aria-label="Main">
            @auth
                <a href="{{ route('dashboard') }}">Dashboard</a>
                <a href="{{ route('ptc.index') }}">PTC</a>
                <a href="{{ route('shortlinks.index') }}">Shortlinks</a>
                @if (\App\Http\Controllers\ReadArticlesController::hasPerUserAdapter(app(\App\Offerwall\AdapterRegistry::class)))
                    <a href="{{ route('read_articles.index') }}">Read</a>
                @endif
                <a href="{{ route('advertise.index') }}">Advertise</a>
                <a href="{{ route('withdraw.index') }}">Withdraw</a>
                <a href="{{ route('referral.index') }}">Referral</a>
                <span class="balance">{{ number_format(auth()->user()->balance_sat) }} sat</span>
                <form method="POST" action="{{ route('logout') }}" style="display:inline;">@csrf<button class="btn-ghost">Logout</button></form>
            @else
                <a href="{{ route('home') }}#how">How it works</a>
                <a href="{{ route('home') }}#advertise">Advertise</a>
                <a href="{{ route('home') }}#defense">Defense</a>
                <a href="{{ route('login') }}">Login</a>
                <a href="{{ route('register') }}" class="nav__cta">Sign up <span aria-hidden="true">→</span></a>
            @endauth
        </nav>
    </div>
</header>

<main id="main" tabindex="-1">
    {{ $slot ?? '' }}
    @yield('content')
</main>

<footer class="site-footer">
    <div class="site-footer__inner">
        <div class="site-footer__col" style="max-width: 22rem;">
            <h3>SatPeek</h3>
            <p style="margin: 0; line-height: 1.6;">A paid-to-click platform that keeps the bots out by design — no relayed captchas, no recycled tokens, no faucets to drain.</p>
        </div>
        <div class="site-footer__col">
            <h3>Product</h3>
            <ul>
                <li><a href="#how">How it works</a></li>
                <li><a href="#defense">What we reject</a></li>
                <li><a href="#numbers">By the numbers</a></li>
            </ul>
        </div>
        <div class="site-footer__col">
            <h3>Payouts</h3>
            <ul>
                <li>FaucetPay — BTC, DOGE, LTC</li>
                <li>Min withdrawal: {{ number_format(config('satpeek.faucetpay.min_withdraw_sat')) }} sat</li>
            </ul>
        </div>
        <div class="site-footer__legal">© {{ date('Y') }} SatPeek</div>
    </div>
</footer>

@stack('body')

@auth
{{-- Anti-adblock + Brave detection. Multiple signals combined so a
     single bypass (CSS class allowlist, `navigator.brave` shim) doesn't
     defeat the check. The result POSTs to /api/adblock/report so the
     server-side AdblockGate middleware can decide whether to gate
     earning routes for this user. Runs on every authenticated page
     load (idempotent — server stamps adblock_checked_at = now). --}}
<div id="sp-adblock-bait" class="ads adsbox doubleclick ad-placement carbon-ads pub_300x250 banner-ad" style="position:absolute;left:-9999px;top:-9999px;height:1px;width:1px;background:transparent" aria-hidden="true">&nbsp;</div>
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!csrf) return;

    async function detect() {
        const signals = [];

        // 1) Bait element. Adblockers hide elements matching common ad
        //    class names — if our bait is hidden / zero-height, adblock
        //    is active. Cookie-clearing also doesn't escape this since
        //    the rule is CSS-injected per-page.
        const bait = document.getElementById('sp-adblock-bait');
        const baitHidden = !bait
            || bait.offsetHeight === 0
            || bait.offsetParent === null
            || getComputedStyle(bait).display === 'none'
            || getComputedStyle(bait).visibility === 'hidden';
        if (baitHidden) signals.push('bait_hidden');

        // 2) Brave detection. The Brave team exposes navigator.brave.isBrave()
        //    as a stable detection API. Brave's built-in shields block ads
        //    by default — per platform policy we count it as adblock.
        let brave = false;
        try {
            if (navigator.brave && typeof navigator.brave.isBrave === 'function') {
                brave = await navigator.brave.isBrave();
                if (brave) signals.push('brave');
            }
        } catch {}

        // 3) Network probe. Try fetching a path adblockers commonly block.
        //    If the request errors out (NetworkError) without a CORS
        //    response, that's a strong adblock signal. Wrap in try/catch
        //    so the page never breaks just because the probe failed.
        let probeBlocked = false;
        try {
            await fetch('/ads/banner.js?_=' + Date.now(), {method: 'HEAD', cache: 'no-store'});
        } catch {
            probeBlocked = true;
            signals.push('probe_blocked');
        }

        const adblockDetected = baitHidden || probeBlocked;

        try {
            await fetch('/api/adblock/report', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    adblock_detected: adblockDetected,
                    brave_detected: brave,
                    signals,
                }),
            });
        } catch {
            // Best-effort. AdblockGate will treat us as stale → blocked
            // until the next successful report.
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', detect, {once: true});
    } else {
        detect();
    }
})();
</script>
@endauth

</body>
</html>
