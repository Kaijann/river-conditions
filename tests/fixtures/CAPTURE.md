# Fixture capture

Captured 2026-10-13T21:13Z from `https://api.waterdata.usgs.gov/ogcapi/v1/collections` (without API key).

| Fixture | HTTP | Notes |
|---|---|---|
| `location-06037500.json` | 200 | Madison River near West Yellowstone, MT · Montana |
| `location-06043500.json` | 200 | Gallatin River near Gallatin Gateway, MT · Montana |
| `location-invalid.json` | 200 | features: 0 |
| `latest-06037500.json` | 200 | parameters: 00010, 00060, 00065, 00095 |
| `latest-06043500.json` | 200 | parameters: 00060, 00065 |
| `continuous-06037500.json` | 200 | points: 670, next link: 0 |
| `latest-with-temp.json` | 200 | site: USGS-01036390, parameters: 00010, 00060, 00065, 00095, 00300, 00400, 63680 |
| `location-search-1.json` | 400 | CQL2 `monitoring_location_name ILIKE '%madison%' AND state_name='Montana'` → features: 0, Bad CQL text |
| `location-search-2.json` | 200 | CQL2 `monitoring_location_name LIKE '%Madison%' AND state_name='Montana'` → features: 5,  |
| `location-search-3.json` | 200 | CQL2 `state_name='Montana' AND site_type_code='ST'` → features: 5,  |

Property names seen in latest-continuous:

```
approval_status, last_modified, monitoring_location_id, parameter_code, qualifier, statistic_id, time, time_series_id, unit_of_measure, value
```

Sample latest-continuous feature:

```json
{
  "time_series_id": "f5cd392811be427ca22aa99da420cd96",
  "monitoring_location_id": "USGS-06037500",
  "parameter_code": "00065",
  "statistic_id": "00011",
  "time": "2026-10-13T20:30:00+00:00",
  "value": "1.69",
  "unit_of_measure": "ft",
  "approval_status": "Provisional",
  "qualifier": null,
  "last_modified": "2026-10-13T20:47:43.500144+00:00"
}
```
