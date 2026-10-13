# River Conditions

[![CI](https://github.com/Kaijann/river-conditions/actions/workflows/ci.yml/badge.svg)](https://github.com/Kaijann/river-conditions/actions/workflows/ci.yml)

A WordPress block and shortcode that show live river conditions (flow, gage height and water temperature) from the **USGS Water Data API**. The data is cached and server-rendered, with no front-end JavaScript.

<!-- Screenshots: add docs/screenshots/card.png, compact.png and editor.png from a real install, then embed them here. -->

## Features

- **Block** (`river-conditions/gauge`) with a live-validated site ID, a server-rendered preview that matches the front end, and card or compact layouts.
- **Shortcode** `[river_conditions]` with the same options. It calls the same renderer, so the markup is identical.
- **Honest data.** Every gauge shows how old its data is. A reading older than 3 hours gets a *stale* notice, provisional values are tagged, and a parameter a gauge doesn't measure is hidden, never shown as 0. The plugin never says whether "fishing is good".
- **Caching that survives outages.** A transient cache with a configurable TTL, a 48-hour last-known-good fallback, a fetch lock against stampedes, and optional WP-Cron warming for gauges used in published posts.
- **Small and accessible.** About 4 KB of CSS, loaded only on pages that show a gauge. A `<figure>`/`<figcaption>` structure, an SVG sparkline with a text summary, trend arrows with text labels, and a provisional tooltip that keyboard users can reach.
- **Themeable** through `--rivcon-*` CSS custom properties.

## Install and use

1. Download `river-conditions-vX.Y.Z.zip` from [Releases](https://github.com/Kaijann/river-conditions/releases) and go to **Plugins → Add New → Upload Plugin**.
2. Add the **River Conditions** block and enter a USGS site ID (for example `USGS-06037500`), or click **Use an example gauge**.
3. Or use the shortcode:

   ```text
   [river_conditions site="USGS-06037500"]
   [river_conditions site="USGS-06043500" layout="compact" show_temp="false"]
   [river_conditions site="USGS-06037500" title="The Madison" temp_unit="C" show_sparkline="no"]
   ```

   Attributes: `site`, `title`, `layout` (`card`/`compact`), `show_flow`, `show_gage_height`, `show_temp`, `show_sparkline`, `temp_unit` (`F`/`C`).

4. **Settings → River Conditions** has the optional API key, cache duration (5–120 minutes), default temperature unit and the attribution link, plus **Test connection** and **Clear cache** buttons.

**API key:** optional, but it raises USGS rate limits ([free signup](https://api.waterdata.usgs.gov/docs/ogcapi/keys/)). The key is stored server-side and never sent to the browser, the REST API or error messages.

To find a site ID, search [waterdata.usgs.gov](https://waterdata.usgs.gov/). The ID is the number in the gauge's URL; bare numbers like `06037500` are accepted too.

## How it works

```text
page view ──► GaugeRenderer ──► GaugeRepository ──► fresh transient? ──yes──► render
                                      │                     no
                                      │                     ▼
                                      │              lock held? ──yes──► last known good
                                      │                     no
                                      ▼                     ▼
                               UsgsClient ──► USGS OGC API (3 requests: site, latest, 7-day flow)
                                      │
                         ok ──► save fresh (TTL) + last-known-good (48 h)
                     failed ──► last known good, shown as stale ── none? ──► "unavailable"
```

- **`src/Api`**: `UsgsClient` builds queries, follows pagination and sets an 8 s timeout and a User-Agent. `UsgsParser` is pure: GeoJSON goes in, normalized arrays come out.
- **`src/Domain`**: the `GaugeReading`/`Measurement` value objects and `Trend` (latest flow vs. about 6 hours earlier, ±5%).
- **`src/Cache/GaugeRepository`**: transients, the last-known-good fallback, the lock, and a cache "generation" so *Clear cache* also works with persistent object caches.
- **`src/Render`**: `GaugeRenderer` is the one place markup lives. `Sparkline` and `Format` are pure.
- **`src/Rest/Controller`**: `river-conditions/v1` has `GET /sites/{id}` (editor validation), `POST /render` (editor preview) and `POST /test` (settings). Each route has a capability check and an args schema.

The pure classes (`UsgsParser`, `Trend`, `Sparkline`, `Format`) make no WordPress calls and are unit-tested directly.

## Development

Requirements: PHP 8.1+, Composer, Node 22, Docker (for wp-env).

```bash
composer install && npm install
npm run build                 # or: npm start (watch)
npx wp-env start              # http://localhost:8888  (admin / password)
bin/set-api-key.sh            # optional: stores $USGS_API_KEY in the local site
```

| Command | What it runs |
|---|---|
| `composer lint` | PHPCS with WordPress Coding Standards + PHPCompatibility |
| `composer analyse` | PHPStan level 6 with WordPress stubs |
| `composer test` | PHPUnit unit tests (Brain Monkey, no WordPress needed) |
| `composer check` | all three |
| `npm run lint:js` / `lint:css` | ESLint / Stylelint (`@wordpress/scripts`) |
| `bin/smoke-test.sh` | end-to-end checks against a running wp-env site |
| `bin/fetch-fixtures.sh` | captures real USGS responses into `tests/fixtures/` |
| `composer make-pot` | regenerates `languages/river-conditions.pot` (also run by the release workflow) |

Step-by-step local testing, including how to simulate a USGS outage, is in [docs/TESTING.md](docs/TESTING.md).

CI (`.github/workflows/ci.yml`) runs PHPCS, PHPStan and PHPUnit on PHP 8.1 and 8.3, plus JS/CSS lint, the build, a CSS size budget, a zip-contents check, and a wp-env smoke test that activates the plugin and renders the block and shortcode on a real WordPress.

## Release and deploy

Tagged releases build an immutable zip on GitHub Releases. Staging deploys pre-release tags, production deploys release tags, and a rollback redeploys the previous tag. See [docs/DEPLOY.md](docs/DEPLOY.md).

## Data notes

- USGS real-time data is mostly **provisional** and can be revised. The block tags it.
- Many gauges have **no temperature sensor**. The stat is simply hidden.
- USGS is **retiring the legacy `waterservices.usgs.gov` APIs** (announced for early 2027). This plugin uses only the new [Water Data OGC API](https://api.waterdata.usgs.gov/docs/ogcapi/). API findings are recorded in [docs/USGS-NOTES.md](docs/USGS-NOTES.md).

## How this was built

Built AI-first with agents (Cursor, K-AI, Claude Code) and reviewed line by line by me.

## Roadmap

- Editor site search by name (the API's CQL2 `ILIKE` filter returned "Bad CQL text" in testing; see USGS-NOTES)
- "% of normal for today" (USGS statistics service)
- Map view
- Saved rivers for logged-in users
- Email alerts on thresholds
- NOAA tides

## License

[GPL-2.0-or-later](LICENSE). Data courtesy of the U.S. Geological Survey.
