<?php

return [
    'captcha' => [
        // The full flow on a real form is: type email → type password → drag
        // captcha (6-10 s) → click submit. 25-30 s upper bounds were short
        // enough to fire `too_slow_relay` / `challenge_expired` on honest users
        // who paused to read field labels. 60 s still rules out human-relay
        // services (which round-trip to a remote solver in 30-90 s) while giving
        // real users a working window.
        'ttl_ms' => (int) env('CAPTCHA_TTL_MS', 60000),
        'min_solve_ms' => (int) env('CAPTCHA_MIN_SOLVE_MS', 800),
        'max_solve_ms' => (int) env('CAPTCHA_MAX_SOLVE_MS', 60000),
        'min_points' => (int) env('CAPTCHA_MIN_POINTS', 20),
        // High-DPI mice driving pointermove on a 6-10 s curve at the client's
        // 8 ms throttle (~125 Hz) emit up to ~1250 samples. The previous cap
        // of 600 guaranteed `too_many_points` for any honest stream. Bot ceiling
        // is enforced via dt_jitter / jerk_entropy, not raw sample count.
        'max_points' => (int) env('CAPTCHA_MAX_POINTS', 1500),
        // How far an individual user sample can drift from the canonical curve
        // before the shape check rejects it. Goal marker render radius is 12px,
        // so the tolerance must comfortably exceed that.
        'shape_tolerance_px' => (float) env('CAPTCHA_SHAPE_TOLERANCE_PX', 48.0),
        'expected_dt_median_ms_min' => (int) env('CAPTCHA_DT_MEDIAN_MS_MIN', 8),
        'expected_dt_median_ms_max' => (int) env('CAPTCHA_DT_MEDIAN_MS_MAX', 80),
        'min_dt_jitter_ratio' => (float) env('CAPTCHA_DT_JITTER_RATIO_MIN', 0.10),
        'min_completion_dwell_ms' => (int) env('CAPTCHA_DWELL_MS_MIN', 100),
        // Radius (in canvas pixels) within which trailing samples are treated
        // as "still". 2 px was unrealistically tight — human hand jitter on a
        // mouse / trackpad is typically 4-7 px during a hold.
        'completion_dwell_radius_px' => (float) env('CAPTCHA_DWELL_RADIUS_PX', 8.0),
        // Minimum jerk-entropy (bits) of the drag — bots with fixed Δt and
        // smooth Bezier replays drop near 0; humans land 2.0+.
        'min_jerk_entropy' => (float) env('CAPTCHA_JERK_ENTROPY_MIN', 1.2),
    ],

    'bot_score' => [
        'suspect' => (float) env('BOTSCORE_SUSPECT', 0.30),
        'likely_bot' => (float) env('BOTSCORE_LIKELY_BOT', 0.60),
        'ban' => (float) env('BOTSCORE_BAN', 0.85),
        'weights' => [
            'response_time' => 0.20,
            'trajectory_entropy' => 0.20,
            'failure_rate' => 0.15,
            'fingerprint_consistency' => 0.15,
            'tls_fingerprint' => 0.10,
            'heartbeat_gap' => 0.10,
            'asn_datacenter' => 0.10,
            // Defence-in-depth ASN check against the operator-maintained
            // DATACENTER_ASNS list. Lower weight than the live reputation
            // signal because the static list lags real-world datacenter
            // emergence — it's a precise positive (high confidence when
            // it fires), not a coverage signal. ScoreEngine renormalises
            // by total weight so adding it doesn't mute the others.
            'asn_static_list' => 0.05,
        ],
    ],

    'datacenter_asns' => array_filter(
        array_map(
            'trim',
            explode(',', (string) env('DATACENTER_ASNS', ''))
        )
    ),

    'faucetpay' => [
        'api_key' => env('FAUCETPAY_API_KEY'),
        'api_base' => env('FAUCETPAY_API_BASE', 'https://faucetpay.io/api/v1'),
        'min_withdraw_sat' => (int) env('FAUCETPAY_MIN_WITHDRAW_SAT', 1000),
    ],

    // BitcoTasks publisher integration. Per the docs (fetched 2026-04-27)
    // there is NO REST API for listing offers — publisher integration is
    // offerwall-iframe-only. The api_key / publisher_id are interpolated
    // into the iframe URL <iframe src="https://bitcotasks.com/offerwall/
    // <api_key>/<user_id>">; the s2s_secret signs the postback (MD5 of
    // subId+transId+reward+secret). usd_to_sat converts the postback's
    // decimal `payout` (USD) into satoshis for the balance ledger.
    // ip_allowlist defaults to BitcoTasks's published postback IP.
    'bitcotask' => [
        'publisher_id' => env('BITCOTASK_PUBLISHER_ID'),
        'api_key' => env('BITCOTASK_API_KEY'),
        'api_base' => env('BITCOTASK_API_BASE', 'https://bitcotasks.com'),
        's2s_secret' => env('BITCOTASK_S2S_SECRET'),
        'usd_to_sat' => (float) env('BITCOTASK_USD_TO_SAT', 0),
        'ip_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BITCOTASK_IP_ALLOWLIST', '45.14.135.48'))
        ))),
    ],

    'referral' => [
        'commission_pct' => (int) env('REFERRAL_COMMISSION_PCT', 10),
    ],

    'ads' => [
        // Platform commission added on top of the per-view reward when
        // computing the advertiser's cost. cost = reward × (1 + pct/100).
        'commission_pct' => (int) env('ADS_COMMISSION_PCT', 25),
        // Auto-approve every submission (skip admin review). Default false —
        // admin must approve from Filament. Flip on to remove human review.
        'auto_approve' => filter_var(env('ADS_AUTO_APPROVE', false), FILTER_VALIDATE_BOOLEAN),
        // Bid bounds (sat) per view.
        'reward_min_sat' => (int) env('ADS_REWARD_MIN_SAT', 1),
        'reward_max_sat' => (int) env('ADS_REWARD_MAX_SAT', 100),
        // Duration bounds (sec).
        'duration_min_sec' => (int) env('ADS_DURATION_MIN_SEC', 5),
        'duration_max_sec' => (int) env('ADS_DURATION_MAX_SEC', 120),
        // Total views bounds.
        'views_min' => (int) env('ADS_VIEWS_MIN', 100),
        'views_max' => (int) env('ADS_VIEWS_MAX', 1000000),
    ],

    // URL-shortener publisher APIs. Keys here become the names referenced by
    // the ShortlinkProviderRegistry and surfaced in the Filament admin action.
    // Two transports are supported:
    //   - 'query' — token is a query parameter, JSON response (btcut family):
    //       GET <api_base>?api=<token>&url=<long>&alias=<custom>&format=json
    //   - 'path'  — token is in the path, plain-text response (ouo family):
    //       GET <api_base>/<token>?s=<long>
    // Providers with no api_token in the env are silently filtered out by
    // ShortlinkProviderRegistry::configuredNames(), so it's safe to leave
    // them registered while the operator collects credentials over time.
    'shortlink_providers' => [
        'btcut' => [
            'label' => 'btcut.io',
            'transport' => 'query',
            'api_base' => env('BTCUT_API_BASE', 'https://btcut.io/api'),
            'api_token' => env('BTCUT_API_TOKEN', ''),
        ],
        'cuty' => [
            'label' => 'cuty.io',
            'transport' => 'query',
            'api_base' => env('CUTY_API_BASE', 'https://cuty.io/api'),
            'api_token' => env('CUTY_API_TOKEN', ''),
        ],
        'exe' => [
            'label' => 'exe.io',
            'transport' => 'query',
            'api_base' => env('EXE_API_BASE', 'https://exe.io/api'),
            'api_token' => env('EXE_API_TOKEN', ''),
        ],
        'shrtfly' => [
            'label' => 'shrtfly.com',
            'transport' => 'query',
            'api_base' => env('SHRTFLY_API_BASE', 'https://shrtfly.com/api'),
            'api_token' => env('SHRTFLY_API_TOKEN', ''),
        ],
        'ouo' => [
            'label' => 'ouo.io',
            'transport' => 'path',
            'api_base' => env('OUO_API_BASE', 'https://ouo.io/api'),
            'api_token' => env('OUO_API_TOKEN', ''),
        ],
    ],

    'offerwalls' => [
        // Default is *empty* — site runs on internal admin-managed inventory
        // (Filament PtcAd / Shortlink resources). Add 'bitcotask' here only
        // after the publisher account has been approved.
        'enabled' => array_filter(
            array_map('trim', explode(',', (string) env('OFFERWALLS_ENABLED', '')))
        ),
    ],

    'ip_reputation' => [
        // Hard off-switch — when true (or implicitly via local/testing env)
        // the application binds NullProvider and never calls IPHub/ProxyCheck.
        'disabled' => filter_var(env('IP_REPUTATION_DISABLED', false), FILTER_VALIDATE_BOOLEAN),
        // Cache TTL for hits and misses respectively.
        'cache_ttl' => (int) env('IP_REPUTATION_CACHE_TTL', 86400),
        'cache_negative_ttl' => (int) env('IP_REPUTATION_CACHE_NEG_TTL', 600),
        // Risk score (0-100) at or above which the IpReputationGate hard-blocks.
        'gate_min_risk' => (int) env('IP_REPUTATION_GATE_MIN_RISK', 70),

        'iphub' => [
            'api_key' => env('IPHUB_API_KEY'),
            'api_base' => env('IPHUB_API_BASE', 'https://v2.api.iphub.info'),
        ],
        'proxycheck' => [
            'api_key' => env('PROXYCHECK_API_KEY'),
            'api_base' => env('PROXYCHECK_API_BASE', 'https://proxycheck.io/v2'),
        ],
        // MaxMind GeoLite2-ASN — local .mmdb lookup, no API key, no quota.
        // The file is operator-supplied (MaxMind license forbids redistribution);
        // when the path is unset or the file missing, the provider degrades to
        // "no signal" rather than failing the request. Mount as a Docker
        // volume in production: `./data/GeoLite2-ASN.mmdb:/var/www/data/...:ro`.
        'maxmind' => [
            'asn_db' => env('MAXMIND_ASN_DB', ''),
        ],
    ],
];
