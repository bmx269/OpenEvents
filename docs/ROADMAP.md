# Roadmap

How the plugin reaches parity with The Events Calendar (TEC), Events Calendar Pro, Event Tickets, Event Tickets Plus, and Category Colors, and fixes the problems users report with them (see `docs/research/tec-support-forum.md`).

## Phase 1: Core foundation ✅

| Feature | Replaces |
| --- | --- |
| Optional Events post type **and/or event features on any existing post types** | TEC (Events post type only) |
| Venues, organizers, hierarchical categories, tags | TEC |
| Start/end, all-day, per-event timezone, cost, website, featured, hide from listings | TEC |
| Occurrences table: indexed, rebuildable, stable IDs | TEC 6 custom tables |
| **Category colours** with automatic contrast, plus a calendar legend | Category Colors add-on |
| Calendar block (month and list, view switcher, responsive agenda on phones, no-JS navigation) | TEC views |
| Upcoming events block, event details block | TEC widget and blocks |
| Site Editor templates; classic-theme fallbacks | TEC templates |
| Setup wizard and settings on the WordPress Design System | TEC settings / onboarding |
| REST occurrences route; core REST with meta | TEC REST |
| Abilities API (list and create events) | new |
| Block Bindings source for core blocks | new |
| iCal module: per-event `.ics`, feed, Google Calendar links | TEC iCal |
| WP-CLI: rebuild, stats, generate | new |
| Performance contract: constant query cost at 5,000+ events | TEC pain point #6 |

## Phase 2: TEC parity and migration

- **TEC importer** (admin screen and `wp open-events import-tec`), following `docs/research/tec-data-model.md`:
  - Batched and resumable. It runs with Sync suspended, then rebuilds.
  - Idempotent through `_oe_source_tec_id`.
  - Maps category colour term meta.
  - Optional 301 redirects from old permalinks.
- CSV import (TEC column names accepted).
- Day view; keyword search and category/venue filter bar on the calendar block.
- Event status: scheduled, cancelled, postponed (with a reason).
- `Event` JSON-LD with Offer, Place, and Organizer.
- Venue map embed (OpenStreetMap; Google optional).
- PHPUnit (repository, sync, generator, formatter) and Playwright (views, wizard, editor) in CI on PHP 8.2–8.5 and WP 7.1–trunk. Query-count budgets enforced in CI.

## Phase 3: Recurrence module (free)

- RFC 5545 RRULE editor, RDATE/EXDATE, and a "this / this and following / all" editing scope.
- Rolling horizon (default 2 years) extended by cron, so the row count stays bounded.
- Single-occurrence overrides as detached child posts linked by `_oe_series_parent`.
- Import of TEC Pro `_EventRecurrence` rules.

## Phase 4: RSVP module

- `oe_ticket_types` / `oe_attendees` tables. Capacity is enforced with an atomic conditional update.
- RSVP form in event details: going/not going, guests, waitlist, opt out of the public list.
- Emails, admin attendee list (search, CSV export, manual check-in), per-occurrence RSVPs.
- Event Tickets RSVP importer for v1, v2, and migrated data (`docs/research/event-tickets-data-model.md`), keeping security codes.

## Phase 5: Tickets module

- Paid ticket types, sale windows, attendee fields.
- Orders tables and a checkout block; the gateway interface with manual, then Stripe, then a WooCommerce adapter. No platform fees.
- QR tickets, a check-in REST endpoint and screen, refunds from the admin, reports.
- Tickets Commerce importer.

## Phase 6: Pro views and extras

- Week, photo, map, and summary views (through `open_events_calendar/calendar/views`).
- Additional fields (filterable), duplicate event, related events, virtual events.
- Admin event list built on DataViews.

## Phase 7: Ecosystem

- Community submissions with moderation.
- Scheduled ICS/Google Calendar imports.
- Multilingual (WPML/Polylang) without autoloaded caches.
- More abilities (update event, RSVP, check in).
