#!/usr/bin/env bash
#
# Capture real USGS Water Data API responses as test fixtures and probe the
# API features the plugin depends on. Run locally or via the "Fixtures"
# GitHub Actions workflow.
#
#   USGS_API_KEY=... bin/fetch-fixtures.sh      # key optional
#
# Writes tests/fixtures/*.json and tests/fixtures/CAPTURE.md (a summary to
# copy into docs/USGS-NOTES.md).
set -euo pipefail

BASE="https://api.waterdata.usgs.gov/ogcapi/v1/collections"
OUT="$(cd "$(dirname "$0")/.." && pwd)/tests/fixtures"
KEY_PARAM=""
if [[ -n "${USGS_API_KEY:-}" ]]; then
	KEY_PARAM="&api_key=${USGS_API_KEY}"
fi
mkdir -p "$OUT"
SUMMARY="$OUT/CAPTURE.md"

# fetch NAME URL: save pretty JSON to $OUT/NAME.json, print the HTTP status.
fetch() {
	local name="$1" url="$2" tmp status
	tmp="$(mktemp)"
	status="$(curl -sS -L -o "$tmp" -w '%{http_code}' -A 'RiverConditions fixture capture (+https://github.com/Kaijann/river-conditions)' "${url}${KEY_PARAM}")"
	if jq . "$tmp" > "$OUT/$name.json" 2>/dev/null; then :; else cp "$tmp" "$OUT/$name.json"; fi
	rm -f "$tmp"
	echo "$status"
}

# params NAME: list parameter codes in a latest-continuous fixture.
params() {
	jq -r '[.features[]?.properties.parameter_code] | unique | join(", ")' "$OUT/$1.json" 2>/dev/null || echo "?"
}

{
	echo "# Fixture capture"
	echo
	echo "Captured $(date -u +%Y-%m-%dT%H:%MZ) from \`${BASE}\` ($( [[ -n "$KEY_PARAM" ]] && echo 'with' || echo 'without' ) API key)."
	echo
	echo "| Fixture | HTTP | Notes |"
	echo "|---|---|---|"
} > "$SUMMARY"

row() { echo "| \`$1.json\` | $2 | $3 |" >> "$SUMMARY"; }

s=$(fetch location-06037500 "$BASE/monitoring-locations/items?f=json&id=USGS-06037500&skipGeometry=true")
row location-06037500 "$s" "$(jq -r '.features[0].properties | "\(.monitoring_location_name // "?") · \(.state_name // "?")"' "$OUT/location-06037500.json" 2>/dev/null)"

s=$(fetch location-06043500 "$BASE/monitoring-locations/items?f=json&id=USGS-06043500&skipGeometry=true")
row location-06043500 "$s" "$(jq -r '.features[0].properties | "\(.monitoring_location_name // "?") · \(.state_name // "?")"' "$OUT/location-06043500.json" 2>/dev/null)"

s=$(fetch location-invalid "$BASE/monitoring-locations/items?f=json&id=USGS-00000000&skipGeometry=true")
row location-invalid "$s" "features: $(jq '.features | length' "$OUT/location-invalid.json" 2>/dev/null)"

s=$(fetch latest-06037500 "$BASE/latest-continuous/items?f=json&monitoring_location_id=USGS-06037500&skipGeometry=true&limit=100")
row latest-06037500 "$s" "parameters: $(params latest-06037500)"

s=$(fetch latest-06043500 "$BASE/latest-continuous/items?f=json&monitoring_location_id=USGS-06043500&skipGeometry=true&limit=100")
row latest-06043500 "$s" "parameters: $(params latest-06043500)"

s=$(fetch continuous-06037500 "$BASE/continuous/items?f=json&monitoring_location_id=USGS-06037500&parameter_code=00060&datetime=P7D&skipGeometry=true&limit=1000")
row continuous-06037500 "$s" "points: $(jq '.features | length' "$OUT/continuous-06037500.json" 2>/dev/null), next link: $(jq -r '[.links[]? | select(.rel=="next")] | length' "$OUT/continuous-06037500.json" 2>/dev/null)"

# Find a gauge reporting both water temperature and flow.
fetch temp-candidates "$BASE/latest-continuous/items?f=json&parameter_code=00010&skipGeometry=true&limit=200" > /dev/null
TEMP_SITE=""
for site in $(jq -r '[.features[]?.properties.monitoring_location_id] | unique | .[]' "$OUT/temp-candidates.json" | head -40 || true); do
	s=$(fetch latest-with-temp "$BASE/latest-continuous/items?f=json&monitoring_location_id=${site}&skipGeometry=true&limit=100")
	if [[ "$s" == "200" ]] && jq -e '[.features[]?.properties.parameter_code] | (index("00060") and index("00010"))' "$OUT/latest-with-temp.json" > /dev/null; then
		TEMP_SITE="$site"
		break
	fi
done
rm -f "$OUT/temp-candidates.json"
row latest-with-temp "$s" "site: ${TEMP_SITE:-none found}, parameters: $(params latest-with-temp)"

# CQL2 text filter for editor site search (SPEC §1.4).
s=$(fetch location-search "$BASE/monitoring-locations/items?f=json&skipGeometry=true&limit=5&filter-lang=cql2-text&filter=$(jq -rn --arg f "monitoring_location_name ILIKE '%madison%' AND state_name='Montana'" '$f|@uri')")
row location-search "$s" "CQL2 ILIKE search, features: $(jq '.features | length' "$OUT/location-search.json" 2>/dev/null)"

{
	echo
	echo "Property names seen in latest-continuous:"
	echo
	echo '```'
	jq -r '[.features[]?.properties | keys[]] | unique | join(", ")' "$OUT/latest-06037500.json" 2>/dev/null
	echo '```'
	echo
	echo "Sample latest-continuous feature:"
	echo
	echo '```json'
	jq '.features[0].properties' "$OUT/latest-06037500.json" 2>/dev/null
	echo '```'
} >> "$SUMMARY"

# Never commit the key: it would only be present if USGS echoed it in links.
if [[ -n "${USGS_API_KEY:-}" ]] && grep -rl "${USGS_API_KEY}" "$OUT" > /dev/null; then
	grep -rl "${USGS_API_KEY}" "$OUT" | xargs sed -i "s/${USGS_API_KEY}/REDACTED/g"
fi

cat "$SUMMARY"
