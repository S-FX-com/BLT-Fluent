# BLT Fluent — repo conventions

WordPress plugin per `docs/` and the original spec: collects FluentCRM custom
contact fields at FluentCart checkout, writes them straight to the contact
record. FluentCRM owns the schema; this plugin stores configuration only.

## Versioning and releases

**Every commit bumps the version.** Two places, kept in sync:

- `Version:` header in `blt-fluent.php`
- `Stable tag:` in `readme.txt`

Default to a patch bump (`0.1.0` → `0.1.1`) unless the change is a new
capability worth a minor bump, or breaks configuration/behavior compatibility
worth a major bump — use judgment, but never leave a commit's version
unchanged. Also add a line under `== Changelog ==` in `readme.txt` describing
the change.

**Every merge to `main` gets a GitHub release.** `.github/workflows/release.yml`
runs on push to `main`: reads the `Version:` header, skips if a tag for that
version already exists (so multiple commits landing before a merge only
produce one release), otherwise builds the distributable zip via
`bin/build-zip.sh` and publishes a GitHub release tagged `vX.Y.Z` with the zip
attached. This is also what Plugin Update Checker reads for self-updates (see
`includes/class-updater.php` and the "Release" section in `README.md`) — a
merge to `main` with no version bump produces no new release and no update for
installed sites.

## Tests

`php tests/smoke-test.php` — a small WordPress shim plus a stubbed FluentCRM
reader, no live FluentCart/FluentCRM needed. Run it (and `php -l` on changed
files) before committing. CI (`.github/workflows/ci.yml`) runs the same thing
on PHP 7.4, 8.2 and 8.4.

## Verifying spec assumptions

Several of FluentCart's checkout payload shapes are unconfirmed (see
`docs/VERIFY.md`). Every touchpoint tries multiple detection strategies and
exposes a `blt_fluent/*` filter as a last resort — prefer wiring a real
FluentCart accessor into `Cart_Context::cart_sources()` (or the analogous spot)
over widening a filter's guesswork once the real behavior is confirmed on
equinephotographers.org.
