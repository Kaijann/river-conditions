# Deploying River Conditions

How code gets from a laptop to production, and back again if something goes
wrong. The setup mirrors a typical GitHub + DeployHQ (or similar) pipeline.

## Branches

| Branch | Purpose | Protection |
|---|---|---|
| `main` | Always production-ready | PRs only, CI must pass, 1 review |
| `feature/*`, `fix/*` | Day-to-day work | none |
| `staging` | Optional: what is on the staging site right now | CI must pass |

Work happens on short-lived branches, which reach `main` through pull
requests. CI (`.github/workflows/ci.yml`) runs on every push and PR:

1. PHPCS (WordPress Coding Standards), PHPStan level 6 and PHPUnit, on PHP 8.1 and 8.3
2. ESLint, Stylelint and the block build, with a CSS size budget and a check of the zip contents
3. A wp-env smoke test: real WordPress, plugin activation, block and shortcode rendering, REST permissions, API-key leak check, uninstall

## Releases

Releases are **semantic version tags** with immutable zips.

```bash
# 1. Bump the version in river-conditions.php (header + VERSION),
#    readme.txt (Stable tag), package.json and CHANGELOG.md.
# 2. Merge to main through a PR.
git checkout main && git pull
git tag -a v1.1.0 -m "v1.1.0" && git push origin v1.1.0
```

`.github/workflows/release.yml` checks that the tag matches the plugin
version, regenerates the `.pot`, builds, runs `npm run plugin-zip` (only the
files listed in `package.json` → `files`, matching `.distignore`) and
attaches `river-conditions-v1.1.0.zip` to a GitHub Release.

Pre-release tags (`v1.1.0-rc.1`) are marked as pre-releases.

## Environments

```text
wp-env (local)  ──►  staging                     ──►  production
npx wp-env start     pre-release tags (vX.Y.Z-rc.N)    release tags only (vX.Y.Z)
                     or the staging branch
```

- **Local:** `npx wp-env start`. It is disposable, uses the same PHP version as the minimum (8.1), and has `WP_DEBUG` on.
- **Staging:** the deploy tool watches pre-release tags (or the `staging` branch) and uploads the built zip's contents to `wp-content/plugins/river-conditions/`. Check the block, the shortcode and **Settings → Test connection** there.
- **Production:** the deploy tool deploys **release tags only**, never a branch, so production always runs a build that CI and staging have already seen.

Built files (`build/`) are not committed. The deploy tool either deploys the
release zip, or runs `npm ci && npm run build` in its build step, and excludes
`.distignore` paths.

## Rollback

Redeploy the previous tag. Because releases are immutable zips, this is one
action in the deploy tool, or a manual upload of the previous zip from
GitHub Releases.

What makes this safe:

- **Data is backward compatible.** The plugin stores only the
  `rivcon_settings` option, a few bookkeeping options and transients.
  Older versions ignore keys they don't know.
- **Schema versioning.** `rivcon_db_version` records the options schema.
  Future migrations run step by step in `Plugin::maybe_upgrade()` and must
  stay readable by the previous minor version, so a rollback never needs
  a database restore.
- **Caches self-heal.** Transients expire within the TTL (max 2 hours), and
  last-known-good copies within 48 hours. Settings → **Clear cache** forces
  it immediately.

## Secrets

The USGS API key is entered in **Settings → River Conditions** on each
environment and lives only in that site's database. It is never committed,
never sent to the browser, and redacted from error messages. For local
development, `bin/set-api-key.sh` copies `$USGS_API_KEY` into wp-env. For
CI fixture capture, add a `USGS_API_KEY` repository secret (optional).
