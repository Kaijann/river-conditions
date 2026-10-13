=== River Conditions ===
Contributors: kaishade
Tags: river, usgs, water, fishing, block
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live river flow, gage height and water temperature from the USGS Water Data API, as a block and a shortcode.

== Description ==

River Conditions shows the latest readings from any USGS stream gauge: discharge (cfs) with a 6-hour trend, gage height, water temperature, and a 7-day flow sparkline. It always shows how old the data is, flags provisional values, and switches to a clear "stale" state when a gauge stops reporting.

* Block: "River Conditions" (card or compact layout).
* Shortcode: `[river_conditions site="USGS-06037500"]`.
* Cached with transients, with a 48-hour last-known-good fallback when USGS is unavailable.
* No front-end JavaScript; about 4 KB of CSS, loaded only where a gauge appears.
* Theme it with `--rivcon-*` CSS custom properties.

Data source: U.S. Geological Survey Water Data API (https://api.waterdata.usgs.gov/). Values are mostly provisional and subject to revision by USGS.

== Installation ==

1. Upload the plugin zip from Plugins → Add New → Upload Plugin, then activate it.
2. Add the River Conditions block, or use the shortcode.
3. Optional: add a free USGS API key under Settings → River Conditions to raise rate limits.

== Frequently Asked Questions ==

= Where do I find a site ID? =

Search for your river at https://waterdata.usgs.gov/ and open a gauge. The ID is in the page address, for example USGS-06037500.

= Why is water temperature missing? =

Many gauges do not measure it. The plugin hides values a gauge does not report.

= Does it send my API key to visitors? =

No. Requests to USGS happen on your server; the key is never printed in pages or REST responses.

== External services ==

This plugin requests data from the U.S. Geological Survey Water Data API (https://api.waterdata.usgs.gov/) when a gauge is displayed and its cache has expired. It sends the gauge's site ID and, if you set one, your USGS API key. No visitor data is sent. USGS policies: https://www.usgs.gov/policies-and-notices

== Changelog ==

= 1.0.0 =
* First release.
