# Testing River Conditions locally

Three levels, from quickest to most complete.

## 1. Unit tests and static checks (no WordPress, no Docker)

Requirements: PHP 8.1+ and Composer; Node 22 for the JS checks.

```bash
composer install
composer check        # PHPCS (WordPress standards) + PHPStan level 6 + PHPUnit
npm install
npm run lint:js && npm run lint:css && npm run build
```

`composer test -- --filter RendererTest` runs one test class. Tests in
`tests/Unit/FixtureParserTest.php` use the real USGS responses in
`tests/fixtures/`. Refresh them with `bin/fetch-fixtures.sh` (needs `curl`
and `jq`) or the **Fixtures** workflow in GitHub Actions.

## 2. A local WordPress with wp-env (recommended)

Requirements: Docker Desktop (or Docker Engine) running.

```bash
npm install && npm run build
npx wp-env start            # first run downloads images: a few minutes
```

- Site: http://localhost:8888 · Admin: http://localhost:8888/wp-admin (`admin` / `password`)
- The plugin is mounted from this folder and already active. Run
  `npm start` to rebuild the block automatically while you edit.
- Optional API key: `export USGS_API_KEY=… && bin/set-api-key.sh`

What to try:

1. **Block:** Posts → Add New → insert "River Conditions" → "Use an example
   gauge". Change layout, toggles and unit in the sidebar; the preview
   updates. Type `USGS-123` to see validation.
2. **Shortcode:** in a new page, add
   `[river_conditions site="USGS-06043500" layout="compact"]`
   (Gallatin: no temperature sensor, so no temp stat).
3. **Settings → River Conditions:** Test connection, change cache minutes,
   Clear cache.
4. **Automated end-to-end check:** `bin/smoke-test.sh`. It creates test pages
   and checks rendering, on-demand CSS, REST permissions, the API-key leak
   check and uninstall. It uninstalls the plugin's data at the end;
   re-activate with `npx wp-env run cli wp plugin activate river-conditions`.

Useful commands:

```bash
npx wp-env run cli wp transient delete --all   # force a fresh fetch
npx wp-env logs                                # PHP errors
npx wp-env stop                                # or: npx wp-env destroy
```

### Simulating a USGS outage

WordPress can block all outgoing HTTP with one constant, which looks
exactly like USGS being down:

```bash
npx wp-env run cli wp config set WP_HTTP_BLOCK_EXTERNAL true --raw
```

1. **Fallback:** load a gauge page while USGS is still reachable, then block
   HTTP and delete only the fresh cache (the 48-hour last-known-good copy stays):

   ```bash
   npx wp-env run cli wp eval 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"%transient%rivcon%\" AND option_name NOT LIKE \"%lkg%\" AND option_name NOT LIKE \"%loc%\"" );'
   ```

   Reload: "Live conditions are temporarily unavailable. Showing the reading from …"
2. **Unavailable:** `npx wp-env run cli wp transient delete --all`, then reload:
   "Live conditions are temporarily unavailable." with a link to USGS.
3. **Restore:** `npx wp-env run cli wp config delete WP_HTTP_BLOCK_EXTERNAL`

## 3. Any existing WordPress (Local, MAMP, a staging site)

Download the zip from GitHub Releases (or run `npm run build && npm run plugin-zip`),
then Plugins → Add New → Upload Plugin. `bin/smoke-test.sh` also works
against any install with WP-CLI:

```bash
WP_CLI="wp --path=/path/to/site" SITE_URL=http://mysite.local bin/smoke-test.sh
```
