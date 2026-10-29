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

1. ✅ **RSVP module** (done 2026-10-27; tests in `RsvpTest.php`, `rsvp.spec.js`)
   - Tables: `oe_ticket_types`, `oe_attendees`. Capacity is enforced with one atomic conditional `UPDATE`, so two people can't take the last seat.
   - Configured in the editor's Event panel and saved with the post: capacity, guests per response, deadline, and whether to offer "not going".
   - RSVP form in event details. It works without JavaScript and on cached pages: no nonces, with honeypot, timing, and rate-limit checks instead.
   - Responding again with the same email updates the earlier response. Switching to "not going" frees the seats.
   - Confirmation email with a private link to change or cancel.
   - Admin attendee list: search, filter by event, CSV export, check-in.
2. ✅ **Tickets module** (done 2026-10-27): pay at the door, Stripe, and PayPal; tests in `TicketsTest.php`, `GatewaysTest.php`, `TicketsModuleTest.php`, `tickets.spec.js`
   - Several ticket types per event: price, capacity, sale window. `oe_orders` / `oe_order_items`.
   - Gateway interface. Built-in: "Pay at the door" (manual), Stripe Checkout, and PayPal (Orders v2), all with webhooks. A WooCommerce adapter later.
   - Order emails with check-in codes, refunds from the admin. Next: QR codes on tickets and a door check-in screen.
3. **Google Calendar import** (iCal feed module)
   - Add a calendar by its iCal address (public or secret). No Google API keys or OAuth needed.
   - Scheduled sync. Events are matched by UID, so updates replace rather than duplicate, and removed events are unpublished. Each feed can map to a category.
   - RRULE/EXDATE expansion through `openevents/occurrences/generate`. This is the first piece of the recurrence module.

- ✅ **Waitlists** for RSVPs (automatic promotion) and ticket types (held, time-limited offers); tests in `WaitlistTest.php`, `WaitlistFormsTest.php`, `waitlist.spec.js`.

Requested 2026-10-27 (all done):
- ✅ **Category colours on event details**: coloured category badges in the details panel (they currently show only on calendars).
- ✅ **Click behaviour**: a setting (site default, overridable per Calendar block) to open an event's page or an accessible popup (`<dialog>`) with date, venue, summary, and a link. Links stay real links without JavaScript.
- ✅ **Calendar filter and search**: category filter and keyword search on the Calendar block (GET params, works without JS, enhanced in place).
- ✅ **Template hierarchy**: a base block template for events usable by every event post type, plus per-category and per-post-type templates (e.g. "Single Event: Workshops") selectable in the Site Editor.

Done in Phase 2 groundwork:
- ✅ PHPUnit integration suite with query-count budgets; Playwright suite against Playground; GitHub Actions.
- ✅ Playground demo link, screenshots, and WordPress.org assets, rebuilt on every push.

## Phase 3: TEC parity and migration (importer in progress)

- **TEC importer** (admin screen and `wp openevents import-tec`), following `docs/research/tec-data-model.md`:
  - Batched and resumable. It runs with Sync suspended, then rebuilds.
  - Idempotent through `_oe_source_tec_id`.
  - Maps category colour term meta.
  - Optional 301 redirects from old permalinks.
- **Event Tickets importer** (RSVP and Tickets Commerce), following `docs/research/event-tickets-data-model.md`. Keeps security codes.
- CSV import (TEC column names accepted).
- Day view; venue filter on the calendar block (category filter and keyword search are done).
- Event status: scheduled, cancelled, postponed (with a reason).
- `Event` JSON-LD with Offer, Place, and Organizer.
- Venue map embed (OpenStreetMap; Google optional).
- Add WP trunk and PHP 8.5 to the CI matrix; MySQL job alongside SQLite.

## Phase 4: Recurrence module (free) (in progress, moved ahead of the TEC importer)

- ✅ RRULE engine (RFC 5545 subset), RDATE/EXDATE, expansion into occurrences with stable ids, rolling two-year horizon extended by cron (done 2026-10-28; tests in `RuleTest.php`, `RecurrenceTest.php`, `recurrence.spec.js`).
- ✅ Repeat panel in the editor with a live preview; editing scope "this date / this and following / all" (detach and split into new events linked by `_oe_series_parent`); skip and add dates.
- ✅ Per-date links (`?oe_occurrence=`), event pages showing the requested date, the rule in words, and the next dates; REST preview and date actions.
- Per-occurrence RSVP and tickets (the tables already carry `occurrence_id`).
- Import of TEC Pro `_EventRecurrence` rules (with the TEC importer).
- RRULE export in iCal feeds (dates are exported expanded today).

## Series (done 2026-10-28)

- ✅ Series post type with its own page listing upcoming and past dates; events link to their series; series passes sold on the series page (tests in `SeriesTest.php`, `series.spec.js`).

## Phase 5: Ticketing extras

- ✅ Attendee fields per ticket type and RSVP (done 2026-10-28; tests in `AttendeeFieldsTest.php`, `attendee-fields.spec.js`): ten field types, per-person name and email, saved question sets, answers in the attendee list, REST, and CSV.
- Coupons, shared capacity across ticket types.
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
