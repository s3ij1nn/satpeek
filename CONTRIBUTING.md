# Contributing to SatPeek

Thanks for considering a contribution. SatPeek is a security-focused
codebase — its captcha and bot-detection logic is the product, and the
test suite (especially `tests/BotSimulation/`) is how we measure that the
adversarial guarantees still hold. Every PR runs through that suite plus a
type checker, formatter, and manifest validator before it can land.

## Dev environment

Everything runs in Docker Compose — no host PHP / Composer / Postgres /
Redis required.

```bash
docker compose up -d
# entrypoint runs composer install, copies .env from .env.example,
# generates APP_KEY, runs migrations, seeds the admin user.
open http://localhost:8080
open http://localhost:8080/admin   # admin@satpeek.local / admin123
curl http://localhost:8080/up      # JSON health
```

Useful service commands:

```bash
docker compose logs -f app                      # tail PHP-FPM logs
docker compose exec app php artisan tinker      # REPL
docker compose exec app php artisan schedule:list
```

## Quality gates (run before opening a PR)

The full pipeline GitHub Actions runs is exposed as a single Composer alias:

```bash
docker compose exec app composer ci
```

That's `composer validate --strict` → `pint --test` → `phpstan analyse` →
`php artisan test`. Each step is also runnable on its own:

| Alias | What it runs |
|---|---|
| `composer test` | PHPUnit (130 tests / 393 assertions) |
| `composer lint` | Pint check-only — fails on formatting drift |
| `composer format` | Pint autofix — call this if `lint` complains |
| `composer analyse` | PHPStan / Larastan level 5, **must stay zero errors** |
| `composer ci` | All four in CI order |

`tests/BotSimulation/PlaywrightHeadlessTest.php` is the security regression
suite. **It must stay green** — that's how we measure that the captcha
and bot-detection guarantees still hold against the synthetic CDP-style
attacks the project is designed against.

## Coding conventions

- **Type hints + return types everywhere.** PHPStan level 5 catches
  most slips; the rest is enforced by code review.
- **Eloquent models carry `@property` PHPDoc** for every column and
  `@property-read` for every relation. See `app/Models/PtcView.php`
  for the canonical example. Larastan resolves magic accessors
  through these — without them you'll see baseline-style errors.
- **Comments document WHY, not WHAT.** A line that says
  `// increment counter` next to `$counter++` is noise. A line that
  says `// btcut.io de-dupes by destination, append cache-buster
  to force a fresh slug` next to a `?_r=...` append is gold.
- **Captcha + bot-detection code carries a security-rationale block.**
  See `app/Captcha/TrajectoryTraceProvider.php`'s class docblock for
  the format: enumerate which attacks the construction defeats and
  why. New defenses follow the same structure.
- **Per-click rotating URLs (`/sl/{token}`, `/{ptc,shortlinks}/auth/{token}`)
  are owner-scoped + single-use.** New endpoints in this family must
  preserve both invariants and have a feature test that asserts the
  404 (cross-user) and 410 (already-resolved) cases.

## Commit messages

We follow [Conventional Commits](https://www.conventionalcommits.org/)
with the project's existing prefixes:

| Prefix | Use for |
|---|---|
| `feat(scope):` | New user-visible behavior or admin capability |
| `fix(scope):` | Behavior bug fix |
| `chore:` | Repo maintenance (gitignore, dependencies, baselines) |
| `style:` | Formatting only — no behavioral change (Pint passes) |
| `docs:` | Markdown / docblocks / README / CHANGELOG |
| `test:` | Test-only changes |
| `ci:` | `.github/workflows/*` or `composer.json` scripts changes |

Body should explain **why** the change is needed and **what alternative
was considered**. Reference open follow-ups in CLAUDE.md when relevant.
Footer can include `Closes #N` or `Refs #N`.

Avoid `Co-Authored-By` trailers — the project owner doesn't want them.

## CHANGELOG

Add an entry to `CHANGELOG.md` under `[Unreleased]` for any change a
downstream consumer would want to know about. Skip it for pure
internal refactors that ship no observable behavior change. Format
follows [Keep a Changelog](https://keepachangelog.com/) — buckets:
**Added / Changed / Fixed / Security / Notes**.

A maintainer cuts a release by:

1. Renaming `[Unreleased]` to `[X.Y.Z] — YYYY-MM-DD`
2. Adding a fresh empty `[Unreleased]` above it
3. `git tag -a vX.Y.Z -m "..."` + `git push --tags`
4. `gh release create vX.Y.Z --notes "$(extract X.Y.Z section)"`

## PR review

- CI (lint + phpunit) **must be green** before review.
- Claude Code Review (`anthropics/claude-code-action`) runs
  automatically on every PR open / synchronize and posts inline
  feedback. It's advisory; a human still merges.
- Tag `@claude` in any PR / issue comment to spawn an interactive
  session for deeper questions about the code in scope. Sessions are
  capped at 15 minutes per mention.
- Dependabot PRs skip the auto-review (CI already vets them); use
  `@claude` if you want Claude to look at a specific bump.

## Reporting security issues

If you find a way to defeat the captcha or bot-detection signals,
please **do not** open a public issue. Email the maintainer privately
or open a GitHub Security Advisory on the repo. Public disclosure of
an active bypass damages every operator running this code.

## Open follow-ups

See the bottom of `CLAUDE.md` for the project-level follow-ups that
need real work (e.g., BitcoTask publisher API S2S signature scheme).
First-time contributors looking for a starter task are welcome to pick
one and propose an approach in an issue first.
