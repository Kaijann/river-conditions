#!/usr/bin/env bash
#
# Store $USGS_API_KEY in the local wp-env site's settings, keeping the other
# settings. The key lives in the WordPress database only, never in git.
#
#   export USGS_API_KEY=...   # e.g. in your shell profile
#   bin/set-api-key.sh
set -euo pipefail
: "${USGS_API_KEY:?Set USGS_API_KEY first}"
key="${USGS_API_KEY//[^A-Za-z0-9_-]/}"
npx wp-env run cli wp eval "\$s = get_option( 'rivcon_settings', array() ); \$s = is_array( \$s ) ? \$s : array(); \$s['api_key'] = '${key}'; update_option( 'rivcon_settings', \$s ); echo 'API key saved.';"
