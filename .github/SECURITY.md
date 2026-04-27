# Security Policy

SatPeek's captcha and bot-detection logic *is* the product. Public
disclosure of an active bypass damages every operator running this
code, so the project treats security reports as the highest-priority
inbound work and asks reporters to use the private channel below.

## Reporting a vulnerability

**Open a GitHub Security Advisory:**
https://github.com/s3ij1nn/satpeek/security/advisories/new

That route notifies only the maintainer, keeps the conversation private
until a fix ships, and lets us issue a CVE later if appropriate.
**Do not open a public issue, PR, or Discussion** for a security
vulnerability.

A useful report includes:

- A concrete reproduction (HTTP requests, captcha trace, headers,
  observed response). Treat it like a feature `Reproduction steps`
  block — numbered, runnable from a fresh `docker compose up`.
- Commit SHA or release tag the bypass works against.
- Why the existing defences (jerk-entropy / Δt jitter / shape
  Frechet distance / dwell / fingerprint binding / per-click
  rotation / owner-scoping) don't catch it.
- Suggested fix direction if one comes to mind — not required, but
  often shortens the back-and-forth.

Please do **not** include real production tokens, real user PII, or
operator credentials in the report. Synthetic / scrubbed values that
demonstrate the bug are sufficient.

## What we treat as a security issue

Anything that lets a non-human actor earn rewards, drain a budget,
or reach an admin-only surface they shouldn't:

- Captcha bypass — solving the trajectory trace via 2captcha-style
  human relay, Bezier replay, VLM, headless CDP, or any synthetic
  trace shape that should fail jerk-entropy / Δt jitter / Frechet
  distance / dwell / response-time-window checks.
- Bot-detection signal evasion — a payload that consistently scores
  `trust` while exhibiting bot characteristics (uniform Δt, missing
  fingerprint, datacenter ASN, scripted UA family, etc.).
- Per-click auth-URL replay — defeating the owner-scoped 404 or the
  single-use 410 on `/sl/{token}`, `/shortlinks/auth/{token}`, or
  `/ptc/auth/{token}`.
- Token / fingerprint leakage — a path that surfaces a JA4
  fingerprint hash, an unhashed browser fingerprint, an
  `epoch_token`, or a shortener API token to a party that shouldn't
  see it.
- Reward credit without a verified click / view — anything that
  writes a positive `delta_sat` to `balance_ledgers` without going
  through the captcha + heartbeat / hold + token guards.
- Withdrawal abuse — bypassing tier review, faking a verified
  withdrawal, or replaying a `ProcessWithdrawalJob`.
- Filament admin escape — privilege escalation, accessing
  `/admin/*` as a non-admin user, mutating read-only triage rows
  (`PtcView`, `ShortlinkClick`).

We will *also* take seriously, but treat as standard application
security rather than core-mission:

- Authentication / authorization holes (session fixation, CSRF,
  privilege escalation between users).
- SQL / command / template injection.
- Cryptographic primitive misuse on the encrypted shortener tokens
  or signed URL paths.
- Dependency vulnerabilities (Dependabot already opens PRs; report
  is welcome if we miss one).

## What's *not* a security issue

- Reports that the captcha is "too hard" or that legitimate users
  occasionally hit `too_slow_relay`. File a regular bug report; the
  policy knobs in `config/satpeek.php` are designed to be tuned.
- Behaviour that the docs describe as intentional. The captcha
  intentionally rejects synthetic uniform-Δt traces — that's a
  feature, not a bug.
- "DOS by spamming /api/captcha/issue" — we throttle at the
  infrastructure layer (Nginx / Cloudflare in production); please
  open an Operations issue instead.

## Supported versions

The project is pre-1.0 and treats `main` as the only fully-supported
branch. We patch the latest minor release for security issues; older
minors are out of scope.

| Version | Status |
|---|---|
| `main` | actively patched |
| `0.1.x` | patches accepted via 0.1.x branch on request |
| `< 0.1.0` | unsupported (pre-public-release) |

## Response timeline

- **Acknowledgement**: within 72 hours of the Security Advisory
  being opened. If you don't hear back in that window, please
  re-ping by adding a comment.
- **Triage**: within 7 days. We will tell you whether we're
  treating the report as in-scope and what severity it gets
  (CVSS-style).
- **Fix target**:
  - Critical / actively exploited → private patch within 14 days,
    coordinated disclosure within 30 days of the patch landing.
  - High → 30 days to patch, 30 days to disclose.
  - Medium / Low → bundled into the next minor release.

We don't currently run a paid bounty programme; credit in the
release notes (or anonymously, if you prefer) is what we offer.

## After a fix lands

- The advisory becomes public on the agreed disclosure date.
- A CHANGELOG entry under `### Security` references the CVE and
  the commit that fixed it.
- The reporter is credited in the advisory unless they ask to stay
  anonymous.
