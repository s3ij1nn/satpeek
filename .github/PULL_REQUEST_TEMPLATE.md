<!--
Thanks for the PR. The form below mirrors the CONTRIBUTING.md
checklist; filling it in honestly speeds review and gives the
auto code-review bot useful context.
-->

## Summary

<!-- One or two sentences. Why does this change exist?
     If it fixes an issue, link with `Closes #N`. -->

## Type of change

<!-- Tick what applies. -->

- [ ] feat — new user-visible behaviour or admin capability
- [ ] fix — behavioural bug fix
- [ ] chore — repo maintenance / dependencies / baselines
- [ ] style — formatting only (Pint passes)
- [ ] docs — markdown / docblocks / README / CHANGELOG
- [ ] test — test-only changes
- [ ] ci — `.github/workflows/*` or composer scripts

## What changed and why

<!-- The one paragraph a future reader needs to understand the diff
     without re-reading the conversation that produced it. Mention
     alternatives you considered and why you didn't pick them. -->

## Test plan

<!-- For every behavioural change: how did you verify it? Both happy
     path and at least one failure path. Reference the test file +
     case if you added coverage.

     Example:
     - tests/Feature/Shortlinks/RotationTest.php → new
       `test_each_sl_redirect_mints_a_freshly_shortened_url` proves
       two consecutive /sl/{token} follows return distinct URLs and
       call shortener->shorten() with distinct cache-busted inputs.
     - Manually: `composer ci` green locally. /shortlinks → click →
       /sl/{token} → 302 to https://provider.test/AAAAA, second click
       302's to BBBBB.
-->

## Security impact

<!-- Be honest. Even "no impact" is a valid answer worth stating.

     Things to think about:
     - Could this make any existing captcha / bot-detection signal
       weaker (lower entropy, missed sample, easier replay)?
     - Could this leak destination URLs / tokens / fingerprints
       outside the request that issued them?
     - Could this regress per-click rotation, owner-scoping, or
       single-use semantics on the auth-token URLs?

     If you find a *current* bypass while working on this PR,
     STOP — don't push it. Email the maintainer or open a GH
     Security Advisory instead. See CONTRIBUTING.md "Reporting
     security issues". -->

## Checklist

- [ ] `docker compose exec app composer ci` passes locally
      (validate + Pint + PHPStan zero + 130+ tests green)
- [ ] `tests/BotSimulation/` still passes
- [ ] New PHPStan errors? Fixed in source — no baseline regrowth
- [ ] Eloquent model touched? `@property` / `@property-read`
      docblock kept current
- [ ] Captcha / bot-detection / auth-URL change? Security-rationale
      block in the class docblock updated
- [ ] User-visible change? `CHANGELOG.md` `[Unreleased]` updated
- [ ] Conventional Commit subject (`feat(scope):` / `fix(scope):` etc.)
- [ ] No `Co-Authored-By` trailers (project preference)
