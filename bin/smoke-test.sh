#!/usr/bin/env bash
#
# End-to-end smoke test against a running wp-env site (CI and local):
# creates pages with the block and the shortcode, fetches them, and checks
# the output. USGS is live here, so the gauge may legitimately be in any
# state (ok, stale, unavailable); the checks cover what must always hold.
set -euo pipefail

WP="npx wp-env run cli wp"
SITE_URL="http://localhost:8888"

create_page() {
	$WP post create --post_type=page --post_status=publish --post_title="$1" --post_content="$2" --porcelain 2>/dev/null | tr -dc '0-9'
}

check_page() {
	local label="$1" id="$2" html
	html="$(curl -fsS "${SITE_URL}/?page_id=${id}")"
	echo "== ${label}: page ${id}"
	echo "$html" | grep -o '<figure[^>]*rivcon-gauge[^>]*>' | head -1
	echo "$html" | grep -q 'class="[^"]*rivcon-gauge' || { echo "FAIL: no gauge markup"; exit 1; }
	echo "$html" | grep -q 'river-conditions-gauge-css' || { echo "FAIL: stylesheet not enqueued"; exit 1; }
	if echo "$html" | grep -q 'rivcon-gauge__value'; then
		echo "state: has a flow value"
	elif echo "$html" | grep -q 'rivcon-gauge__alert'; then
		echo "state: alert ($(echo "$html" | grep -o 'rivcon-gauge__alert" role="status">[^<]*' | head -1))"
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
echo "$html" | grep -q '<!-- River Conditions: no USGS gauge for USGS-123 -->'
! echo "$html" | grep -q 'rivcon-gauge__'

echo "== pages without a gauge load no plugin CSS"
! curl -fsS "${SITE_URL}/?page_id=${PLAIN_ID}" | grep -q 'river-conditions-gauge-css'

echo "== REST routes are registered and protected"
curl -fsS "${SITE_URL}/?rest_route=/river-conditions/v1" | grep -q '"namespace":"river-conditions\\/v1"'
status=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${SITE_URL}/?rest_route=/river-conditions/v1/render")
test "$status" = "401" || { echo "FAIL: /render should need auth, got $status"; exit 1; }

echo "== the API key never reaches the page"
$WP option update rivcon_settings '{"api_key":"SMOKEKEY123","cache_minutes":15,"temp_unit":"F","show_attribution":true}' --format=json
$WP transient delete --all
! curl -fsS "${SITE_URL}/?page_id=${BLOCK_ID}" | grep -q 'SMOKEKEY123'

echo "== uninstall removes options"
$WP plugin deactivate river-conditions
$WP plugin uninstall river-conditions --skip-delete
! $WP option get rivcon_settings 2>/dev/null

echo "All smoke checks passed."
