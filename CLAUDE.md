# Notes for AI coding agents

This repository is maintained with AI assistance and human review. Agents
working here should:

1. Keep pure logic (`UsgsParser`, `Trend`, `Sparkline`, `Format`) free of
   WordPress calls and covered by unit tests.
2. Put all markup in `GaugeRenderer`. The block, the shortcode and the
   editor preview must share it.
3. Escape all output, sanitize all input, and check capabilities and nonces on
   every admin action. The USGS API key must never reach the browser, REST
   responses or error messages.
4. Follow the real USGS API over assumptions: capture fixtures with
   `bin/fetch-fixtures.sh` and record findings in `docs/USGS-NOTES.md`.
5. Run `composer check`, `npm run lint:js`, `npm run lint:css` and
   `npm run build` before committing. Keep commits small.
6. Not add runtime dependencies (no jQuery, no front-end JS, no `vendor/`
   in the zip) without asking.
