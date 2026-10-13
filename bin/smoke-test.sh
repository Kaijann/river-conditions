#!/usr/bin/env bash
#
# End-to-end smoke test against a running wp-env site (CI and local):
# creates pages with the block and the shortcode, fetches them, and checks
# the output. USGS is live here, so the gauge may legitimately be in any
# state (ok, stale, unavailable); the checks cover what must always hold.
set -euo pipefail

# Override to run against another install, e.g. WP_CLI="wp --path=/srv/wp" SITE_URL=http://localhost:8080
WP="${WP_CLI:-npx wp-env run cli wp}"
SITE_URL="${SITE_URL:-http://localhost:8888}"

# refute DESCRIPTION CMD...: fail if CMD succeeds. (Bash's "! cmd" never trips set -e.)
refute() {
	local what="$1"
	shift
	if "$@"; then
		echo "FAIL: ${what}"
		exit 1
	fi
}

create_page() {
	$WP post create --post_type=page --post_status=publish --post_title="$1" --post_content="$2" --porcelain 2>/dev/null | tr -dc '0-9'
}

check_page() {
	local label="$1" id="$2" html
	html="$(curl -fsS "${SITE_URL}/?page_id=${id}")"
	echo "== ${label}: page ${id}"
	# grep -o | head can SIGPIPE under pipefail; these lines are informational only.
	grep -o '<figure[^>]*rivcon-gauge[^>]*>' <<<"$html" | head -1 || true
	if ! grep -q 'class="[^"]*rivcon-gauge' <<<"$html"; then
		echo "FAIL: no gauge markup on page ${id}. Diagnostics:"
		echo "-- HTML length: ${#html}; first 1500 chars of <body>:"
		grep -o '<body.*' <<<"$html" | cut -c1-1500 || true
		echo "-- post:"
		$WP post get "$id" --fields=ID,post_title,post_status,post_content || true
		echo "-- block registered / direct render:"
		$WP eval 'var_dump( WP_Block_Type_Registry::get_instance()->is_registered( "river-conditions/gauge" ) ); echo do_blocks( get_post( '"$id"' )->post_content );' || true
		echo "-- debug.log:"
		$WP eval 'echo @file_get_contents( WP_CONTENT_DIR . "/debug.log" );' | tail -20 || true
		exit 1
	fi
	grep -q 'river-conditions-gauge-css' <<<"$html" || { echo "FAIL: stylesheet not enqueued"; exit 1; }
	if grep -q 'rivcon-gauge__value' <<<"$html"; then
		echo "state: live value $(grep -o 'rivcon-gauge__value">[^<]*' <<<"$html" | head -1 | cut -d'>' -f2 || true) cfs"
		grep -q 'rivcon-gauge__spark' <<<"$html" && echo "sparkline: yes" || echo "sparkline: no"
	else
		echo "state: $(grep -o 'rivcon-gauge__\(alert\|flag\)" role="status">[^<]*' <<<"$html" | head -1 || true)"
	fi
}

BLOCK_ID=$(create_page "Block" '<!-- wp:river-conditions/gauge {"siteId":"USGS-06037500"} /-->')
SHORT_ID=$(create_page "Shortcode" '[river_conditions site="USGS-06043500" layout="compact"]')
INVALID_ID=$(create_page "Invalid" '[river_conditions site="USGS-123"]')
PLAIN_ID=$(create_page "Plain" 'No gauge here.')

check_page "block" "$BLOCK_ID"
check_page "shortcode (compact)" "$SHORT_ID"

echo "== invalid site renders only a comment"
html="$(curl -fsS "${SITE_URL}/?page_id=${INVALID_ID}")"
grep -q '<!-- River Conditions: no USGS gauge for USGS-123 -->' <<<"$html"
refute "invalid site rendered gauge markup" grep -q 'rivcon-gauge__' <<<"$html"

echo "== pages without a gauge load no plugin CSS"
plain="$(curl -fsS "${SITE_URL}/?page_id=${PLAIN_ID}")"
refute "plugin CSS loaded on a page without a gauge" grep -q 'river-conditions-gauge-css' <<<"$plain"

echo "== REST routes are registered and protected"
curl -fsS "${SITE_URL}/?rest_route=/river-conditions/v1" | grep -q '"namespace":"river-conditions\\/v1"'
status=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${SITE_URL}/?rest_route=/river-conditions/v1/render")
test "$status" = "401" || { echo "FAIL: /render should need auth, got $status"; exit 1; }

echo "== the API key never reaches the page"
$WP option update rivcon_settings '{"api_key":"SMOKEKEY123","cache_minutes":15,"temp_unit":"F","show_attribution":true}' --format=json
$WP transient delete --all
keyed="$(curl -fsS "${SITE_URL}/?page_id=${BLOCK_ID}")"
refute "API key appears in page HTML" grep -q 'SMOKEKEY123' <<<"$keyed"

echo "== uninstall removes options"
$WP plugin deactivate river-conditions
$WP plugin uninstall river-conditions --skip-delete
refute "rivcon_settings survived uninstall" $WP option get rivcon_settings

echo "All smoke checks passed."
