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

## Phase 2: Free RSVP, ticketing, and Google Calendar (in progress)

Priority order, set 2026-10-27. Everything here is free: no paid tier, no platform fees.

1. **RSVP module**
   - Tables: `oe_ticket_types`, `oe_attendees`. Capacity is enforced with one atomic conditional `UPDATE`, so two people can't take the last seat.
   - Configured in the editor's Event panel and saved with the post: capacity, guests per response, deadline, and whether to offer "not going".
   - RSVP form in event details. It works without JavaScript and on cached pages: no nonces, with honeypot, timing, and rate-limit checks instead.
   - Responding again with the same email updates the earlier response. Switching to "not going" frees the seats.
   - Confirmation email with a private link to change or cancel.
   - Admin attendee list: search, filter by event, CSV export, check-in.
2. **Tickets module** (requires RSVP)
   - Several ticket types per event: price, capacity, sale window. `oe_orders` / `oe_order_items`.
   - Gateway interface. Built-in: "Pay later / at the door" (manual), then Stripe Checkout with webhooks. A WooCommerce adapter later.
   - Order emails, refunds from the admin, QR check-in.
3. **Google Calendar import** (iCal feed module)
   - Add a calendar by its iCal address (public or secret). No Google API keys or OAuth needed.
   - Scheduled sync. Events are matched by UID, so updates replace rather than duplicate, and removed events are unpublished. Each feed can map to a category.
   - RRULE/EXDATE expansion through `openevents/occurrences/generate`. This is the first piece of the recurrence module.

Done in Phase 2 groundwork:
- ✅ PHPUnit integration suite with query-count budgets; Playwright suite against Playground; GitHub Actions.
- ✅ Playground demo link, screenshots, and WordPress.org assets, rebuilt on every push.

## Phase 3: TEC parity and migration

- **TEC importer** (admin screen and `wp openevents import-tec`), following `docs/research/tec-data-model.md`:
  - Batched and resumable. It runs with Sync suspended, then rebuilds.
  - Idempotent through `_oe_source_tec_id`.
  - Maps category colour term meta.
  - Optional 301 redirects from old permalinks.
- **Event Tickets importer** (RSVP and Tickets Commerce), following `docs/research/event-tickets-data-model.md`. Keeps security codes.
- CSV import (TEC column names accepted).
- Day view; keyword search and category/venue filter bar on the calendar block.
- Event status: scheduled, cancelled, postponed (with a reason).
- `Event` JSON-LD with Offer, Place, and Organizer.
- Venue map embed (OpenStreetMap; Google optional).
- Add WP trunk and PHP 8.5 to the CI matrix; MySQL job alongside SQLite.

## Phase 4: Recurrence module (free)

- Builds on the RRULE expansion from the Google Calendar import.
- RRULE editor in the Event panel; editing scope: "this / this and following / all".
- Rolling horizon (default 2 years) extended by cron, so the row count stays bounded.
- Single-occurrence overrides as detached child posts linked by `_oe_series_parent`.
- Per-occurrence RSVP and tickets (the tables already carry `occurrence_id`).
- Import of TEC Pro `_EventRecurrence` rules.

## Phase 5: Ticketing extras

- Attendee info fields per ticket type, waitlists, coupons, shared capacity across ticket types.
- WooCommerce gateway adapter.
- Check-in screen for door staff (mobile-friendly, works offline-first).

## Phase 6: Pro views and extras

- Week, photo, map, and summary views (through `openevents/calendar/views`).
- Additional fields (filterable), duplicate event, related events, virtual events.
- Admin event list built on DataViews.

## Phase 7: Ecosystem

- Community submissions with moderation.
- Scheduled ICS/Google Calendar imports.
- Multilingual (WPML/Polylang) without autoloaded caches.
- More abilities (update event, RSVP, check in).
