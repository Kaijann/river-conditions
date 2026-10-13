# USGS Water Data API: notes

Findings about the USGS Water Data OGC API that shaped the parser and client.
**Status: verified against the live v1 API on 2026-10-13** (first fixture
capture, see `tests/fixtures/CAPTURE.md`). Where the docs and the live API
disagreed, the code follows the live API.

## From the documentation

- **Base URL.** The docs and the R `dataRetrieval` package long used
  `/ogcapi/v0/`. USGS has since moved to a stable `/ogcapi/v1/`. The plugin
  uses `v1` (`UsgsClient::BASE_URL`, one constant to change).
- **Legacy shutdown.** The legacy `waterservices.usgs.gov` APIs are being
  decommissioned. Recent third-party migration notes give 2027-02-22, which
  is why this plugin never touches them.
- **Format.** Without `f=json` a browser-like request gets HTML, so every
  request sets `f=json`. Responses are GeoJSON `FeatureCollection`s, and
  `skipGeometry=true` drops the coordinates we don't use.
- **latest-continuous properties:** `latest_continuous_id`, `time_series_id`,
  `monitoring_location_id`, `parameter_code`, `statistic_id`, `time`,
  `value`, `unit_of_measure`, `approval_status`, `qualifier`,
  `last_modified`. The site **name and state are not in this collection**
  (SPEC §1 assumed they were), so the plugin reads them from
  `monitoring-locations`.
- **`value` is a string.** It is cast with `is_numeric()` + `(float)`.
  Non-numeric values (equipment malfunction or ice codes, null) are skipped,
  so the stat hides instead of showing 0.
- **Several series per parameter.** A site can report, for example, two
  temperature sensors, or a daily statistic next to the instantaneous value.
  The parser prefers `statistic_id = 00011` (instantaneous), then the newest
  reading.
- **Durations are rejected.** `datetime=P7D` returns HTTP 400
  `"Unknown string format: P7D"`, although some client docs show it. The
  client sends an explicit interval instead:
  `datetime=2026-10-06T21:00:00Z/2026-10-13T21:00:00Z` (`UsgsClient::interval()`).
- **Paging.** Results are paged with `limit` plus a `links[rel=next]` URL.
  At 15-minute data, 7 days is about 672 points, under `limit=1000`. 5-minute
  gauges need a second page, and the client follows up to 4 pages. The next
  link may not carry `api_key`, so the client re-appends it.
- **Monitoring location IDs** are `AGENCY-NUMBER`, e.g. `USGS-06037500`.
  The public page is `https://waterdata.usgs.gov/monitoring-location/USGS-06037500/`.

## Decisions taken without live verification

These are the places a real response could prove us wrong. The fixture
tests (`tests/Unit/FixtureParserTest.php`) exist to catch them.

1. `latest-continuous` is queried **without** `parameter_code`, so all
   parameters come back and the parser picks 00060/00065/00010. This avoids
   depending on comma-list support for `parameter_code`.
2. `approval_status` is read, with `approvals_status` as a fallback spelling.
3. Site names are run through `Format::site_name()`: names that come back
   ALL CAPS with a trailing state code ("MADISON RIVER NEAR WEST
   YELLOWSTONE MT") are title-cased and the code dropped (the state is
   shown separately). Mixed-case names are left untouched.
4. Editor search by name (CQL2 `ILIKE`) is **not shipped**. If the
   `location-search` fixture returns matches, build it per SPEC §4.

## Capture results (2026-10-13, v1, no API key)

| Check | Result |
|---|---|
| `/ogcapi/v1/` | Live, HTTP 200 |
| `monitoring-locations?id=USGS-06037500` | Madison River near West Yellowstone, MT · Montana |
| `monitoring-locations?id=USGS-06043500` | Gallatin River near Gallatin Gateway, MT · Montana |
| `latest-continuous`, Madison | 00010 (temp), 00060, 00065, 00095 |
| `latest-continuous`, Gallatin | 00060, 00065 (**no temperature**: the "hidden stat" case) |
| Temperature + flow gauge | `USGS-01036390` |
| `continuous` with `datetime=P7D` | **HTTP 400**, see above |
| CQL2 `ILIKE` name search | **HTTP 400 "Bad CQL text"**, so editor search is not shipped |

Other findings:

- **Names are mixed case** in v1 and end in the state code
  (`"Madison River near West Yellowstone, MT"`). `Format::site_name()` drops
  the code when the state is shown. A few legacy names are still upper case
  (`"SAN MARCIAL ADDED"`) and get title case.
- **`USGS-00000000` is a real site** (San Marcial Added, NM), so the
  invalid-site fixture uses a 15-digit ID.
- `latest-continuous` properties match the docs exactly: `approval_status`,
  `last_modified`, `monitoring_location_id`, `parameter_code`, `qualifier`,
  `statistic_id`, `time`, `time_series_id`, `unit_of_measure`, `value`
  (string). The site name is not among them.
- `numberMatched` is not returned; `numberReturned` is.
