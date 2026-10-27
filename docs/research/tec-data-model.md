# The Events Calendar (free, v6.18.2): data model for the importer

From a shallow clone of [the-events-calendar](https://github.com/the-events-calendar/the-events-calendar). The `common/` submodule was not inspected; items marked *(inferred)* depend on it.

## Post types and taxonomies
- `tribe_events` (rewrite slug `event`), `tribe_venue`, `tribe_organizer`. There is no series post type in free (`tribe_event_series` comes from Pro).
- `tribe_events_cat` plus core `post_tag`.
- Category colours (term meta): `tec-events-cat-colors-primary`, `-secondary`, `-text`, `-priority`, `-hidden`.

## Event meta
| Key | Format | Map to |
| --- | --- | --- |
| `_EventStartDate` / `_EventEndDate` | `Y-m-d H:i:s`, local | `_oe_start` / `_oe_end` |
| `_EventStartDateUTC` / `_EventEndDateUTC` | `Y-m-d H:i:s`, UTC, can be missing on old data | derived |
| `_EventTimezone` | IANA name **or** `UTC+5`-style offset | `_oe_timezone` (convert offsets) |
| `_EventAllDay` | `yes` / `no` / absent | `_oe_all_day` |
| `_EventDuration` | seconds; stale or missing for all-day events | recompute |
| `_EventVenueID` | one row | `_oe_venue_id` |
| `_EventOrganizerID` | **one row per organizer**, ordered by `meta_id` | `_oe_organizer_ids` |
| `_EventCost` | can be **several rows** | `_oe_cost` |
| `_EventCurrencySymbol`, `_EventCurrencyPosition` (`prefix`/`postfix`) | | `_oe_currency_symbol` |
| `_EventURL` | | `_oe_url` |
| `_EventHideFromUpcoming` | `yes` / absent (only existence matters) | `_oe_hide_from_listings` |
| `_tribe_featured` | `1` / absent | `_oe_featured` |
| `_tribe_events_status` (`scheduled`/`canceled`/`postponed`), `_tribe_events_status_reason` | | future `_oe_status` |
| `_EventShowMap`, `_EventShowMapLink` | mixed truthy values (`1`, `true`, `'false'`) | display options |
| sticky in month view | `menu_order = -1` (not meta) | |

## Venue and organizer meta
- Venue: `_VenueAddress`, `_VenueCity`, `_VenueState`, `_VenueProvince`, `_VenueStateProvince`, `_VenueZip`, `_VenueCountry`, `_VenuePhone`, `_VenueURL`, `_VenueLat`, `_VenueLng`, `_VenueShowMap`, `_VenueShowMapLink`. The venue post also carries duplicate `_EventShowMap*` keys.
- Organizer: `_OrganizerEmail`, `_OrganizerPhone`, `_OrganizerWebsite`.
- Both are saved generically as `'_Venue' . $key` / `'_Organizer' . $key`, so arbitrary extra keys can exist.

## Custom tables (derived from post meta, which is the source of truth)
- `tec_events`: `event_id` PK, `post_id` UNIQUE, `start_date`, `end_date`, `timezone`, `start_date_utc`, `end_date_utc`, `duration`, `updated_at`, `hash`.
- `tec_occurrences`: `occurrence_id` PK, `event_id`, `post_id`, local and UTC start/end, `duration` (signed mediumint, max about 97 days), `hash` UNIQUE, `updated_at`.
- Recurrence (Pro) is stored in `_EventRecurrence` (serialized rules/exclusions) and uses provisional occurrence ids.

## Free features and surfaces (for parity)
- Views: list, month, day, latest-past. Widgets: events list, QR code.
- Blocks: `tribe/event-datetime`, `-venue`, `-organizer`, `-price`, `-website`, `-links`, `-category`, `-tags`, `featured-image`, `classic-event-details`.
- REST: `tribe/events/v1` (events, venues, organizers, categories, tags, Swagger docs) and `tec/v1` with full CRUD.
- iCal: `?ical=1`, 30 events by default, VTIMEZONE blocks, ORGANIZER as `MAILTO`.
- CSV importer columns: `event_name`, `event_start_date`, `event_start_time`, …, `venue_*`, `organizer_*`.
- JSON-LD for Event, Venue, and Organizer, with an Offer on events.
- Settings option `tribe_events_calendar_options`. Keys include `eventsSlug`, `postsPerPage`, `multiDayCutoff`, `dateWithYearFormat`, `defaultCurrencySymbol`, `embedGoogleMaps`, and others.

## Importer gotchas
1. Read organizer and cost meta with `get_post_meta( $id, $key, false )`.
2. Recompute duration and UTC from local time plus timezone. Convert `UTC±N` offsets.
3. Treat `'false'` and `'no'` as false.
4. Block-editor events contain `wp:tribe/*` blocks in `post_content`. Strip them or map them to `openevents/event-details`.
5. Events with `_tribe_aggregator_*` meta were imported from a feed. Keep the origin in meta for re-sync.
6. Store the source id in `_oe_source_tec_id` so reruns update instead of duplicating.
