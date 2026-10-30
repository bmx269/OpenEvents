# Architecture

OpenEvents is a single plugin: a core calendar plus optional modules (iCal today; recurrence, RSVP, and tickets planned). Site owners turn modules on and off under **Events → Settings → Modules**, and third-party plugins can register their own.

Four priorities drive the design:

1. **Performance at library scale.** Thousands of events, with listings that cost about the same as a plain page.
2. **Accessibility.** WCAG 2.2 AA for every display and admin screen, enforced by automated axe scans in CI.
3. **Not breaking sites.** No fatals on upgrade, no runtime rewrite flushes, existing content left alone.
4. **Open extension.** Every feature is built on public hooks and APIs that add-ons can use too.

| Item | Value |
| --- | --- |
| Slug / text domain | `openevents` |
| PHP namespace | `OpenEvents\` (PSR-4, `inc/`) |
| Hook prefix | `openevents/` |
| Option prefix | `openevents_` |
| DB table | `{$wpdb->prefix}oe_occurrences` |
| Post type / taxonomy / meta prefix | `oe_` / `_oe_` |
| Minimum versions | WordPress 7.1, PHP 8.2 |

## Directory layout

```
openevents.php   Bootstrap: constants, autoloader, activation hooks
uninstall.php              Opt-in data removal
inc/
  functions.php            Public PHP API (stable contract)
  Plugin.php               Wires services on plugins_loaded
  Installer.php            Schema (dbDelta), versioned upgrades, rewrite flush flag, soft-fail notice
  Settings.php             Typed accessor for options, event post type resolution
  Content/                 Post types, taxonomy, registered meta, Event model
  Occurrences/             Occurrence table: Generator, Sync, Repository, Occurrence
  Rest/                    /openevents/v1/occurrences
  Integrations/            Abilities API, Block Bindings source
  Admin/                   React admin app host (settings + setup wizard), list columns
  Frontend/                Blocks, calendar views, templates, formatter, category colours, editor loader
  Modules/                 Module contract, registry, bundled modules
  Cli/                     wp openevents rebuild | stats | generate
src/
  admin/                   Settings + setup wizard (@wordpress/ui, @wordpress/admin-ui)
  editor/                  Editor sidebar panels, block bindings editor source
  blocks/                  calendar, upcoming-events, event-details
templates/                 Block templates (block themes), PHP fallback (classic themes)
dev/                       Sample data, benchmark mu-plugin and blueprint (not shipped)
docs/research/             TEC/Event Tickets data models and support-forum findings
```

## Where events live

The site owner chooses in the setup wizard, and can change it later in settings:

- **A dedicated `oe_event` post type**, with its own archive (on by default, can be turned off).
- **Any existing public, REST-enabled post types** (Posts, Pages, a custom "Programs" type, and so on). Several can be enabled at once.

A post in an enabled type **is an event only once it has a start date**. Enabling a type changes nothing about existing posts:

- they get no occurrence rows
- no markup is injected
- their templates are untouched

The editor shows an "This is an event" toggle on those types. Event categories, the meta fields, and the "Event date" admin column are attached to every enabled type. `Settings::event_post_types()` (filterable through `openevents/event_post_types`) is the single source of truth.

Venues (`oe_venue`), organizers (`oe_organizer`), and event categories (`oe_event_category`, hierarchical, with a colour per term) are shared by all event types.

## Data model

Event meta is registered with `register_post_meta()`: underscore-prefixed, `show_in_rest`, auth-checked, and revisioned where the post type supports revisions. The editor sidebar writes through core REST, so any client gets the same fields.

| Meta key | Type | Meaning |
| --- | --- | --- |
| `_oe_start` / `_oe_end` | `Y-m-d H:i:s` | Wall-clock time in the event's timezone |
| `_oe_timezone` | string | IANA timezone; empty means the site timezone |
| `_oe_all_day` | bool | |
| `_oe_venue_id` / `_oe_organizer_ids` | int / int[] | |
| `_oe_cost`, `_oe_currency_symbol`, `_oe_url` | string | Display only; tickets will have their own prices |
| `_oe_featured`, `_oe_hide_from_listings` | bool | |

### The occurrences table

Post meta describes an event. `oe_occurrences` describes **when it happens**, and every listing reads it, never `meta_query`.

```
id, event_id, sequence                       UNIQUE (event_id, sequence)
start_local, end_local, timezone             wall-clock: places events on calendar days
start_utc, end_utc                           absolute: upcoming/past and ordering
all_day, hide_from_listings                  denormalised display flags
post_type, status                            denormalised from wp_posts so listings never join it
KEY (status, start_local)  KEY (status, end_utc)  KEY (status, start_utc)
```

- **Recurrence is a generator.** The recurrence module hooks `openevents/occurrences/generate` and returns one row per date (see [Recurrence module](#recurrence-module)). Views, REST, iCal, and attendees don't change.
- **Stable IDs.** `Sync` diffs rows by `sequence` and updates in place, so moving an event's time keeps its occurrence ID (verified: id 5012 survives a move). Attendees will reference occurrence IDs.
- **Derived, never canonical.** The table can always be rebuilt from posts and meta (`wp openevents rebuild`), so a failed migration never loses data. That fixes a major TEC pain point.

### Sync

`Occurrences\Sync` watches:

- `save_post`
- `transition_post_status` (scheduled publishing, trash)
- `rest_after_insert_{type}` for every event type
- changes to the schedule meta keys

Syncs are **deferred and de-duplicated per request**. They flush once before the next occurrence read in that request, so reads are never stale, or at shutdown. Unchanged rows aren't rewritten. Bulk importers call `suspend()`/`resume()` and then `rebuild_all()`, which uses keyset pagination and flushes the runtime cache per batch.

## Performance

This is the contract, and it was measured on WordPress 7.1.3 in Playground with 5,001 events over four years, no persistent object cache, and SQLite. Each listing must stay constant-cost as the number of events grows:

| Page | Queries | Comparison |
| --- | --- | --- |
| Plain page (baseline) | 25 | |
| Month grid, 92 events shown | 27 | +2 vs baseline |
| List view, page 1 or page 40 | 26 | offset doesn't matter |
| Past events | 28 | |
| REST, 50 occurrences | 18 | was 68 before batch priming |

How the plugin gets there:

1. **Indexed single-table reads.** Listings filter on `status`/`post_type` copied into `oe_occurrences`, so there's no join to `wp_posts` (it's joined only for keyword search).
2. **Bounded range scans.** "Overlaps the month" would otherwise scan all history. `Sync` tracks the longest occurrence duration, so a month query is `start_local BETWEEN (from − max_duration) AND to`, about a month of index entries.
3. **Batch priming.** `Repository::prime()` loads event posts, meta, terms, category term meta, venues, organizers, and (optionally) featured images in a fixed number of queries. Rendering N events costs O(1) queries.
4. **Two cache layers, object cache only.**
   - Query results, and the rendered calendar HTML, are cached under a last-changed key.
   - The key is bumped on any change that affects output: schedule, title, status, categories, colours, venue or organizer edits, display settings, modules, date/time format, start of week.
   - There are no transients and nothing autoloaded except small options.
5. **No wasted work.**
   - The archive's main `WP_Query` is short-circuited, since the calendar block runs its own query.
   - `COUNT(*)` only runs for paginated views.
   - Admin list dates load in one query per screen.
   - The "Event date" column sorts through the occurrences index.
6. **Front-end weight.** Server-rendered HTML; the only JS is a 683-byte view module that lazy-loads the core router. Block styles are about 5 KB.

`wp openevents stats` prints row counts and `EXPLAIN` plans for the hot queries. `dev/bench-blueprint.json` plus `dev/bench-mu.php` reproduce the numbers above (an `?openevents_bench=1` HTML comment with query count and timings).

## Accessibility

Target: WCAG 2.2 AA. `tests/e2e/accessibility.spec.js` runs axe-core (WCAG 2.0/2.1/2.2, A and AA) on the plugin's own markup: month, list, and past views (desktop and phone), event details, the RSVP form, and the settings, setup, and attendee screens. Any violation fails CI.

Patterns used throughout:
- Semantic structure: the month grid is a `<table>` with a caption and column headers; lists are `<ol>`; details are a `<dl>`; headings follow a configurable level.
- Real links and buttons, so everything works with a keyboard and without JavaScript.
- Focus management after in-place updates: calendar navigation moves focus to the new heading; RSVP moves focus to the result message, which is also a live region.
- Errors are announced (`role="alert"` / live regions), fields have visible labels, groups use `<fieldset>`/`<legend>`, touch targets are at least about 44px.
- Contrast: category colours get automatic black/white text; muted text never uses opacity.

## RSVP module

- Settings live in the `_oe_rsvp` meta object (saved and revisioned with the event) and are mirrored into an `oe_ticket_types` row of kind `rsvp`, keyed by `(event_id, type_key, sequence)` so `sold` survives edits (see Per-date places).
- One `oe_attendees` row per person (guests included) for per-person check-in; a response is a group sharing `group_uuid`.
- Seats: `UPDATE … SET sold = sold + n WHERE … sold + n <= capacity`; no row changed means full. Changing a response reserves or releases the difference.
- Privacy: a second response with an already-used email never changes the first. The owner is emailed a fresh private link (token stored as an HMAC hash) to change or cancel.
- Cache-safe public form: no nonce. Spam defences are a honeypot, a signed timestamp (submissions under 2 seconds are rejected), and a per-IP rate limit (hashed IPs).
- Entry points: REST `POST /openevents/v1/events/{id}/rsvp` (status codes 409 full, 403 closed, 422 invalid, 429 rate-limited), the no-JS `admin-post.php` form, and `Service::respond()` for add-ons.
- Admin: Events → Attendees (DataViews, server-paged) with check-in, cancel, and streamed CSV export protected against spreadsheet formula injection.
- Hooks: `openevents/rsvp/responded`, `openevents/rsvp/cancelled`, `openevents/rsvp/email`, `openevents/attendees/capability`, `openevents/client_ip`.

## Passes and check-in

Every confirmed person (RSVP guest or ticket holder) has a pass, and staff check people in at the door from Events → Check-in.

- **Pass** (`Attendance\Pass`): a page at `/?oe_pass={uuid}.{signature}`, where the signature is an HMAC of the attendee's uuid (site salt). Nothing is stored; the link can go in any email at any time and shows only that person. The page is standalone and printable: event, date, ticket, name, code, and a QR code. It sends `no-cache`, `noindex`, and `Referrer-Policy: no-referrer`; a cancelled booking shows "no longer valid" and no QR code.
- **Where passes are linked:** RSVP confirmations, waitlist promotions, and ticket emails list each person's pass; the order summary and the RSVP change form link them; organisers can open any pass from the attendee list (`pass_url` in `GET /openevents/v1/attendees`).
- **QR codes** (`Attendance\QrCode`): a built-in encoder (byte mode, level M, versions 1 to 10, so up to 213 bytes) that outputs inline SVG, with no library, image, or outside service. It encodes the check-in link `admin.php?page=openevents-check-in&code=…`, so any phone camera opens the check-in screen, and handheld scanners type it into that screen. The end-to-end test decodes the rendered symbol with jsQR.
- **Check-in** (`Attendance\CheckIn`): `GET /openevents/v1/check-in?code=` looks someone up; `POST` checks them in (or undoes it). The `code` may be the code or a scanned link. Staff need `edit_posts`, and then `edit_post` on that attendee's event; otherwise the answer is the same 404 as a wrong code. Checking in is one conditional `UPDATE … WHERE checked_in_utc IS NULL`, so two scanners can't both succeed; the second sees "already checked in at …". Only `going` bookings check in. Warnings: payment due at the door (pay-at-the-door order), a booking for another date, and a called-off date. Fires `openevents/checked_in`.
- **The screen:** a code field that keeps focus (for typing and handheld scanners), camera scanning where the browser has `BarcodeDetector`, a live result with warnings and the date's tally, Undo, and recent check-ins on this device. Opening a scanned link only looks the person up; checking in is a tap, so a link alone can't check anyone in.
- **Schema:** attendance schema 6 indexes `security_code`.

## Event status

An event, or one date of a repeating event, can be cancelled or postponed, with a note for visitors (`Content\EventStatus`).

- **Meta:** `_oe_status` (`scheduled`, `cancelled`, `postponed`) and `_oe_status_reason` for the whole event; `_oe_date_status` (`[ { date: "Y-m-d", status, reason } ]`) for single dates, keyed by local date like skipped dates. The whole event's status wins when it isn't "scheduled".
- **Occurrences** carry the resolved status in `event_status` (Installer schema 3), written by `Generator::row()`. Both meta keys are schedule keys, so a change re-syncs the rows, and listings read it without meta lookups (`Occurrence::is_called_off()`).
- **Bookings close** on a called-off date: `Rsvp\Service::closed_reason()` returns the status, `Checkout::sale_state()` too (not for a series pass, which covers many dates), and waitlist joins are refused. Existing responses can still be cancelled.
- **Shown:** a "Status" row first in the event details (badge and note), a badge in calendar items and list cards (`.oe-status`, the word in the text colour), the popup, iCal (`STATUS:CANCELLED`, or `TENTATIVE` for postponed, plus the label in the summary), and REST (`status`, `status_reason`).
- **Editing:** the Event panel has Status and a note; the Repeat panel's date menu cancels, postpones, or reinstates one date, with a note per date. `EventStatus::set()` validates for code, and the `openevents/set-event-status` ability (needs `edit_post`) lets assistants do the same.
- **TEC import:** `_tribe_events_status` and its reason; without it, a title starting with "[Cancelled]", "Canceled:", or "Postponed –" sets the status. Titles are left as they are.

## Per-date places (repeating events)

Each date of a repeating event has its own RSVP and ticket capacity, so a weekly storytime for 12 takes 12 every week.

- **Rows per date:** `oe_ticket_types` is unique on `(event_id, type_key, sequence)`. The row with sequence 0 is the event's own date; it is the only row for a single event or a series pass, and it holds the definition. Another date gets its own row the first time it's shown or booked (`TicketTypes::for_date()`, an `INSERT IGNORE … SELECT` from the sequence-0 row, so concurrent first bookings share one row). Settings changes reach every date's row; `sold` is kept per row.
- **Why sequences, not occurrence ids:** sequences are derived from the date (see Recurrence), so they survive rebuilds and a date that is removed and added again. The `occurrence_id` column is unused.
- **Everything else follows from the row id:** seats are reserved on the date's row, attendees and order lines point at it, and waitlists are keyed by it, so each date has its own queue. Sale windows close at the row's own date (`TicketTypes::occurrence()`; a series pass uses the series' last date).
- **Which date:** forms book the date being viewed (`Repository::requested()`: a dated URL, else the next date) and post its `sequence`. Only dates on the schedule can create rows, so a made-up sequence is refused. Private links keep their own date: changing an RSVP, leaving a waitlist, or claiming held tickets acts on the date of that response or entry, wherever the link opens. Emails name the booked date and link to its page (`TicketTypes::url()`).
- **Admin:** the attendee and waitlist lists show each person's date; the attendee list filters by date once an event is chosen (`GET /openevents/v1/attendees/dates?event=`, then `sequence=`), and so does its CSV. On a repeating event the editor shows capacity as "for each date".
- **Upgrade:** schema 5 adds the column and drops the old `(event_id, type_key)` unique key, which dbDelta would keep. Existing rows become sequence 0, so bookings made before stay on the event's own date.
- **Not yet:** removing a date that has bookings leaves them on a date no longer on the schedule; the attendee list labels them so.

## Event attributes and calendar filters

- **Attributes** (`Content\Attributes`): site-defined lists such as Audiences or Format, stored in the `attributes` setting as `[ { slug, label } ]` and registered as taxonomies `oe_{slug}` on every event post type: checkbox lists in the editor, REST, admin columns, no public archives. Each shows as a row in the event details. Manage them under Settings → Content.
- **`_oe_details`**: extra labelled rows for the event details (`[ { label, value } ]`), used for imported TEC Pro text fields.
- **Calendar filters:** besides category and search, a "More filters" disclosure with day of the week (`oe_dow[]`) and each attribute (`oe_{slug}[]`) as checkboxes. Values within a filter match any; different filters must all match. Repository arguments: `weekdays` (0–6, local start) and `terms` (taxonomy => term ids). Parameter names come from `CalendarView::params()`.
- **TEC import:** Pro additional fields of type checkbox, radio, or dropdown become attributes with their options as terms; text fields become `_oe_details` rows.

## Recurrence module

- **Storage:** `_oe_recurrence` = `{ rrule, rdates, exdates }`, the three parts of an iCal VEVENT. `rrule` is a standard RFC 5545 string, so rules from iCal feeds, Google Calendar, and TEC map over as text.
  - Supported: FREQ `DAILY`–`YEARLY`, `INTERVAL`, `COUNT`, `UNTIL`, `BYDAY` (with ordinals: `2TU`, `-1FR`), `BYMONTHDAY`, `BYMONTH`, `BYSETPOS`, `WKST`. Other parts are rejected (REST returns 400) rather than expanded wrongly.
  - `UNTIL` may be a date (inclusive, event timezone), a local date-time, or UTC with `Z`.
  - `rdates` are extra local start times; `exdates` are skipped local dates. The event's own `_oe_start` is always the first date, as DTSTART is in iCal.
- **Expansion** (`Rule::starts()`, `Recurrence::starts()`) works on calendar dates and then applies the start's wall-clock time in the event timezone. A 7pm event stays at 7pm across DST, and every date keeps the event's wall-clock length.
- **Sequences** are stable per date: the first date is 0 (as for a single event), and others are `day_number × 8 + slot`. Skipping, adding, or retiming dates never renumbers the others, so occurrence ids held by attendees and links survive.
- **Horizon:** dates are written up to two years past today or the start, whichever is later (`openevents/recurrence/horizon`). A daily cron (`openevents_recurrence_extend`) re-syncs repeating events, which only writes rows that changed.
- **Links to one date** add `?oe_occurrence=Y-m-d` (`openevents_occurrence_url()`); the first date uses the plain permalink, which stays canonical. Event details, block bindings, and the `.ics` download read the requested date (`Repository::requested()`). Details add a "Repeats" row with the rule in words (`Describer`) and the next dates.
- **Editing scopes** (`Series`): skip a date; detach one date into its own event; split the series so a new event carries the rule from that date on (COUNT carries over as the dates still to come). Copies record `_oe_series_parent` and fire `openevents/recurrence/copied`.
- **REST:** `POST /openevents/v1/recurrence/preview` (dates and description without saving, used by the editor) and `POST /openevents/v1/events/{id}/occurrences/{occurrence}/{skip|detach|split}`.

## Series module

- **Post type** `oe_series` (`/series/{slug}/`, REST `series`), under the Events menu. An event belongs to at most one series: `_oe_series_id` on the event (REST, editor picker with "add new"). Dates split off or detached from a repeating event copy it, so they stay in the series.
- **Series page:** the content, then sections from `openevents/series/sections`: upcoming dates (event cards), past dates behind a disclosure, and series passes when Tickets is on. Added through `the_content`, so page builders that print the content show it too.
- **Event details** get a "Series" row linking to the series. Listings and REST take a `series` argument (`openevents_get_occurrences( [ 'series' => id ] )`, `GET /openevents/v1/occurrences?series=id`).
- **Series passes** are ticket types owned by the series post (`_oe_tickets` on the series; `openevents_ticketed_post_types()` includes series when the module is on). They're sold on the series page and stop selling when the series' last date starts, unless a pass sets its own sale end. Deleting a series deletes its pass holders, as for events.

## Module system

```php
interface OpenEvents\Modules\Module {
    public function id(): string;            // 'ical', 'recurrence', 'acme-zoom'
    public function label(): string;
    public function description(): string;
    public function requires(): array;       // module ids this one depends on
    public function boot(): void;            // called only when enabled
    public function install(): void;         // own tables; runs when enabled or version() changes
    public function version(): string;
}
```

Extend `AbstractModule` and register through the `openevents/modules` filter. Enabled IDs are stored in `openevents_modules`, and dependencies are resolved on save and at boot. Modules boot at `plugins_loaded:20`, so add-ons that load after core can still register. Core never references a module class.

## Front end

- **Blocks (apiVersion 3, iframe-safe, server-rendered):**
  - `calendar`: month, week, day, and list views, view switcher, category legend, list pagination. The events archive offers all four; the block defaults to month and list.
  - `upcoming-events`: compact list.
  - `event-details`: date, venue with an OpenStreetMap link, organizers, cost, website, add-to-calendar links.
- **Navigation** is links with `oe_view` / `oe_month` / `oe_date` / `oe_page` / `oe_past`.
  - The Interactivity API router swaps the `data-wp-router-region` in place and moves focus to the new heading.
  - Navigation URLs are `noindex,follow`, links carry `rel=nofollow`, and months are clamped to ±5 years. That closes the crawler traps reported against TEC.
- **Responsive.** The month grid is a semantic `<table>`. Under 600px it becomes an agenda list of only the days that have events.
- **Theme-friendly.**
  - No global typography or colours, only `--oe-accent`, `--oe-border`, `--oe-muted`, `--oe-surface`, and `--oe-radius`, which default to theme.json presets.
  - Category colours are applied with an automatic black-or-white text colour for WCAG contrast.
- **Block themes** get Site Editor-editable templates (`register_block_template`): `single-oe_event`, `archive-oe_event`, `taxonomy-oe_event_category`.
- **Template hierarchy for single events** (block and classic themes):
  `single-{type}-{slug}` → `single-event-category-{category}` (each category, then its parents) → `single-{type}` → `single-event` → `single`.
  - `single-event` is a plugin template for every event post type. For existing post types it only applies when **Use the event template** is on, so dated posts keep their theme template by default.
  - The Site Editor offers "Single Event" and "Single Event: {Category}" under **Add template**.
  - `openevents/single_template_hierarchy` filters the result.
- **Click behaviour:** a site setting (`click_action`), overridable per Calendar block (`clickAction`), chooses between going to the event page and a summary popup.
  - The popup is a native modal `<dialog>`: focus is contained, Escape and backdrop clicks close it, and focus returns to the opener. The title is the dialog's accessible name.
  - It's filled from data on the link itself (no extra request). Links keep their `href`, so the popup degrades to navigation without JavaScript or with a modifier key.
- **Calendar filter and search:** a GET form (`oe_cat`, `oe_s`) with a labelled category dropdown and keyword box. It respects the block's category restriction, persists through navigation, and is noindexed.
- **Classic themes** get a PHP archive template, which can be overridden at `your-theme/openevents/archive.php`, and details prepended to the content.
- **Existing post types** keep their own templates and only gain the details panel. The panel is skipped if the block is already in the content, and can be turned off.

## Admin

- **Settings and setup wizard:** one React app built on the WordPress Design System (`@wordpress/ui` controls, `@wordpress/admin-ui` `Page`). It saves through core `/wp/v2/settings`; the options are registered with REST schemas and the same PHP sanitisers. These packages are bundled by design, so the app is about 448 KB and loads on those two screens only.
- **Setup wizard:** runs after activation, with a one-time redirect that skips bulk activation. Steps:
  1. Where events live (new type and/or existing types, with post counts). It also detects TEC data.
  2. Where the calendar appears (archive, a new Calendar page, or an existing page) and the default view.
  3. Modules.
  4. Next steps.
- **Editor sidebar:** dates with duration-preserving start changes, timezone, venue (type a new name to create one), organizers, cost, website, featured, hidden. It uses `@wordpress/components`, which is the editor's own system.

## Extension APIs

| Surface | What add-ons do with it |
| --- | --- |
| **PHP API** (`inc/functions.php`) | `openevents()`, `openevents_get_event()`, `openevents_is_event()`, `openevents_get_occurrences()`, `openevents_get_upcoming()`, `openevents_format_range()`, `openevents_calendar_url()`, `openevents_occurrence_url()`, `openevents_event_post_types()`, `openevents_is_module_enabled()` |
| **Modules** | `openevents/modules` |
| **Meta** | `openevents/meta_fields` (registered with REST and sanitisation) |
| **Occurrences** | `…/occurrences/generate`, `…/occurrences/synced`, `…/occurrences/query_args`; `Sync::suspend()`, `resume()`, `rebuild_all()` |
| **Recurrence** | `…/recurrence/horizon`, `…/recurrence/copied` |
| **Series** | `_oe_series_id`; `openevents/series/sections`; `series` query argument; `openevents_ticketed_post_types()` |
| **Views** | `…/calendar/views` (register a renderer), `…/calendar/links`, `…/calendar/cache` |
| **Event details** | `…/event_details/rows`, `…/event_details/links`, `…/event_details/sections` (RSVP form, tickets) |
| **Formatting** | `…/format_range` |
| **Attendee fields** | `fields` and `attendee_details` on `_oe_rsvp` and each `_oe_tickets` entry; `GET/POST /openevents/v1/field-sets`; answers in `GET …/attendees` |
| **REST** | `GET /openevents/v1/occurrences`; `POST …/recurrence/preview`; `POST …/events/{id}/occurrences/{occurrence}/{skip,detach,split}`; `…/rest/occurrence` filter; core `/wp/v2/events`, `/venues`, `/organizers`, `/event-categories` with meta |
| **Abilities API** | `openevents/list-events` (read-only), `openevents/create-event`, exposed at `/wp-abilities/v1` for AI and automation clients |
| **Block Bindings** | source `openevents/event` with keys `date`, `start_date`, `start_time`, `end_date`, `end_time`, `venue`, `venue_address`, `venue_url`, `cost`, `website`. Core Paragraph, Heading, and Button blocks can show event data. `…/block_bindings/value` filter for custom keys. |
| **Editor SlotFill** | `OpenEventsEventPanel`, `OpenEventsVenuePanel`, `OpenEventsOrganizerPanel`; the fill receives `{ meta, setMeta, postType }` |
| **Templates** | Site Editor templates; classic override path; `…/skip_archive_query` |
| **WP-CLI** | `wp openevents rebuild`, `stats`, `generate` |
| **Lifecycle** | `openevents/loaded`, `…/modules_booted`, `…/uninstall` |

## Attendee fields (RSVP and tickets)

Questions asked of each person, defined per ticket type (the RSVP settings, or each entry in `_oe_tickets`):

- **Definitions:** `fields` = list of `{ key, type, label, required, help, options }`. Types: `text`, `textarea`, `email`, `tel`, `url`, `number`, `date`, `select`, `radio`, `checkbox` (a checkbox with one option is an "I agree" box). Keys are made from labels and stay stable. `attendee_details` (`none`, `optional`, `required`) asks for each guest's or ticket holder's own name and email.
- **Answers** are stored per attendee in `oe_attendees.fields` (JSON, key → value; checkboxes are lists), so a group booking keeps each person's answers. `Fields::answers()` pairs them with labels; answers to removed questions keep their key as the label.
- **Forms:** one `<fieldset class="oe-person">` per possible person. The view scripts hide and disable groups beyond the chosen quantity (and, for RSVPs, when "not going"), which also keeps them out of the submission. Bindings use `state.groupHidden` / `state.groupRequired`, which are undefined on the server, so without JavaScript every group shows and nothing unused is `required` in the browser; the server checks the groups for the people actually booked.
- **Validation** (`Fields::people()`, `Fields::collect()`) runs before any seat is held. Errors name the person ("Guest 2: …", "Child, ticket 2: …").
- **Input shape** (forms and REST): RSVP `attendees[i][name|email|fields][key]`; checkout `attendees[ticket_type_id][i][…]`.
- **Reporting:** `answers` in `GET /openevents/v1/attendees`, a Details column on the attendee screen, and one CSV column per question.
- **Saved question sets** (`FieldSets`, option `openevents_field_sets`): `GET/POST /openevents/v1/field-sets`, `DELETE …/field-sets/{n}`. Applying a set copies its questions, so editing a set never changes past events.
- **RSVP waitlists ask the same questions** as the RSVP form, checked the same way when joining (`WaitlistService::join()`), because promotion confirms people without another form. Each person's details and answers wait in `oe_waitlist.people` (JSON, the `Fields::people()` shape) and become the attendee rows at promotion. The copy is dropped when the entry leaves the queue (claimed, left, or expired). Ticket waitlists don't ask: claiming an offer goes through checkout, which does.
- **Privacy tools** (`Attendance\Privacy`): Tools → Export Personal Data includes an email's attendees (with answers under their questions), orders, and waitlist entries (with their guests' details and answers). Erase Personal Data anonymises attendees and orders (name, email, answers, IP hash) so seat counts and totals stay right, and deletes waitlist entries. A guest named on someone else's waitlist entry is found by their own email too: the export shows only their part, and erasing blanks only their part, so the entry keeps its place. Suggested privacy policy text is added. Registered by core.
- **Deleting an event deletes its attendance data** (`Attendance\Cleanup`, on `before_delete_post`): attendees, waitlist entries, orders and their lines, and ticket types. Trashing keeps everything. Registered by core, so it runs even with RSVP and Tickets switched off. Fires `openevents/attendance/deleted`.

## Waitlist (RSVP and tickets)

Opt-in per RSVP (`waitlist` in `_oe_rsvp`) and per ticket type (`waitlist` in `_oe_tickets`). Entries live in `oe_waitlist` (`waiting → offered → claimed`, or `expired` / `left`), and the queue is ordered by id.

- `TicketTypes::release()` and capacity increases fire `openevents/seats_released`. `WaitlistService::process()` then fills the freed seats:
  - **RSVP** promotes the next entry that fits straight to "going", creating attendee rows and sending the usual change/cancel link.
  - **Tickets** hold the seats (they're counted in `sold`) and email a claim link valid for `waitlist_offer_hours` (default 24). Checkout with `waitlist_token` buys exactly the held tickets without reserving them again. Unclaimed offers expire through a 5-minute cron and pass down the queue, and so do abandoned claim checkouts, through the normal order release.
- **Fairness:** first come, first served. An entry larger than the free seats keeps its place while smaller ones behind it are served.
- **Safety:** each entry moves with a conditional `UPDATE` before seats are taken with the atomic reserve, and is handed back if the reserve fails. A per-request re-entrancy guard stops expiry-triggered releases from recursing.
- **Privacy and spam:** joining again with an email already on the list only emails a fresh private link, as with RSVPs. The forms are cache-safe: honeypot, signed timestamp, and a per-connection rate limit (`WaitlistService::RATE` joins per `RATE_SPAN` seconds, by IP hash).
- **Surfaces:**
  - The RSVP form switches to "Join the waitlist" when full, and the count shows how many are waiting.
  - The tickets section offers a waitlist form for sold-out types and a claim view behind the private link.
  - REST: `POST /events/{id}/waitlist`, `POST /waitlist/leave`, `GET /waitlist` (with `answers` for organisers), and `POST /waitlist/{id}` (remove).
  - Events → Waitlist (DataViews), with a Details column for answers.
- **Hooks:** `openevents/waitlist/joined`, `promoted`, `offered`, `openevents/waitlist/email`, `openevents/seats_released`.

## Tickets module

Built on the same attendance tables as RSVP, plus orders:

```
oe_ticket_types   (shared with RSVP) kind 'paid', price in settings.price_minor, capacity, sold;
                  one row per date of a repeating event (sequence; see Per-date places)
oe_orders         id, uuid, event_id, status, gateway, gateway_ref, gateway_txn, name, email,
                  total_minor, currency, token_hash, ip_hash, expires_utc, created_utc, meta
oe_order_items    order_id, ticket_type_id, quantity, unit_price_minor
oe_attendees      (shared) one row per ticket; group_uuid = order uuid
```

- **Ticket types** are edited in the editor's Tickets panel and saved as `_oe_tickets` meta. They're mirrored into `oe_ticket_types` rows keyed `tkt-{key}`, so edits keep `sold`. Removed types are archived.
- **Money** is stored as integers in minor units. `Money` knows the zero-decimal currencies and formats with `NumberFormatter`.
- **Checkout** holds every line's seats or none, using the same conditional `UPDATE` as RSVP, for 20 minutes while the buyer pays. A cron sweep (every 5 minutes, and opportunistically on checkout) releases abandoned holds.
- **Order lifecycle:** `pending → completed | expired | failed | cancelled`, `pending → unpaid → completed` (manual), `completed | unpaid → refunded`.
  - Every change is `Orders::transition()`, a conditional `UPDATE … WHERE status IN (…)`, so racing webhooks and returns complete an order exactly once.
  - A late payment after expiry is honoured: seats are re-taken, or force-added with `oversold` flagged and an admin notice.
- **Gateways** implement `Modules\Tickets\Gateway`: `start_payment`, `handle_return`, `handle_webhook`, `refund`, and `settings_fields`. They're registered with `openevents/tickets/gateways`.
  - Settings live in the non-autoloaded `openevents_gateways` option, or in `OPENEVENTS_{GATEWAY}_{KEY}` constants.
  - Admin REST masks secrets.
  - **Manual:** confirms immediately as unpaid.
  - **Stripe:** hosted Checkout Sessions with idempotency keys, confirmed on return by retrieving the session, plus `Stripe-Signature` HMAC webhooks with a 5-minute tolerance. Refunds go through `/v1/refunds`.
  - **PayPal:** Orders v2 with capture on return, and the captured amount and currency are checked. Webhooks are verified with `verify-webhook-signature`, and capture approved orders whose buyers never returned. Refunds go through the captures API. The OAuth token is cached until expiry.
- **Public form** is cache-safe like RSVP (honeypot, signed timestamp, rate limit) and has a no-JS `admin-post` path. On return from a provider, the buyer sees their order through an HttpOnly cookie holding the order token; the email link carries it too.
- **Admin:** Events → Orders (DataViews: mark paid, refund with confirmation, cancel) and Settings → Tickets (currency and gateways).
- **Hooks:** `openevents/tickets/order_completed`, `order_unpaid`, `order_released`, `order_refunded`, `oversold`, `openevents/tickets/email`, `openevents/tickets/gateways`.

Setup steps for each provider are in `docs/payments.md`.
