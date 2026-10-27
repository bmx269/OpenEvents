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

- **Recurrence is a generator.** The recurrence module will hook `openevents/occurrences/generate` and return N rows. Views, REST, iCal, and attendees don't change.
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

- Settings live in the `_oe_rsvp` meta object (saved and revisioned with the event) and are mirrored into an `oe_ticket_types` row of kind `rsvp`, keyed by `(event_id, type_key)` so `sold` survives edits.
- One `oe_attendees` row per person (guests included) for per-person check-in; a response is a group sharing `group_uuid`.
- Seats: `UPDATE … SET sold = sold + n WHERE … sold + n <= capacity`; no row changed means full. Changing a response reserves or releases the difference.
- Privacy: a second response with an already-used email never changes the first. The owner is emailed a fresh private link (token stored as an HMAC hash) to change or cancel.
- Cache-safe public form: no nonce. Spam defences are a honeypot, a signed timestamp (submissions under 2 seconds are rejected), and a per-IP rate limit (hashed IPs).
- Entry points: REST `POST /openevents/v1/events/{id}/rsvp` (status codes 409 full, 403 closed, 422 invalid, 429 rate-limited), the no-JS `admin-post.php` form, and `Service::respond()` for add-ons.
- Admin: Events → Attendees (DataViews, server-paged) with check-in, cancel, and streamed CSV export protected against spreadsheet formula injection.
- Hooks: `openevents/rsvp/responded`, `openevents/rsvp/cancelled`, `openevents/rsvp/email`, `openevents/attendees/capability`, `openevents/client_ip`.

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
  - `calendar`: month and list views, view switcher, category legend, list pagination.
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
| **PHP API** (`inc/functions.php`) | `openevents()`, `openevents_get_event()`, `openevents_is_event()`, `openevents_get_occurrences()`, `openevents_get_upcoming()`, `openevents_format_range()`, `openevents_calendar_url()`, `openevents_event_post_types()`, `openevents_is_module_enabled()` |
| **Modules** | `openevents/modules` |
| **Meta** | `openevents/meta_fields` (registered with REST and sanitisation) |
| **Occurrences** | `…/occurrences/generate`, `…/occurrences/synced`, `…/occurrences/query_args`; `Sync::suspend()`, `resume()`, `rebuild_all()` |
| **Views** | `…/calendar/views` (register a renderer), `…/calendar/links`, `…/calendar/cache` |
| **Event details** | `…/event_details/rows`, `…/event_details/links`, `…/event_details/sections` (RSVP form, tickets) |
| **Formatting** | `…/format_range` |
| **REST** | `GET /openevents/v1/occurrences`; `…/rest/occurrence` filter; core `/wp/v2/events`, `/venues`, `/organizers`, `/event-categories` with meta |
| **Abilities API** | `openevents/list-events` (read-only), `openevents/create-event`, exposed at `/wp-abilities/v1` for AI and automation clients |
| **Block Bindings** | source `openevents/event` with keys `date`, `start_date`, `start_time`, `end_date`, `end_time`, `venue`, `venue_address`, `venue_url`, `cost`, `website`. Core Paragraph, Heading, and Button blocks can show event data. `…/block_bindings/value` filter for custom keys. |
| **Editor SlotFill** | `OpenEventsEventPanel`, `OpenEventsVenuePanel`, `OpenEventsOrganizerPanel`; the fill receives `{ meta, setMeta, postType }` |
| **Templates** | Site Editor templates; classic override path; `…/skip_archive_query` |
| **WP-CLI** | `wp openevents rebuild`, `stats`, `generate` |
| **Lifecycle** | `openevents/loaded`, `…/modules_booted`, `…/uninstall` |

## Planned: RSVP, tickets, and payments

Data contracts are fixed now so core won't change later. Research on Event Tickets' data model is in `docs/research/event-tickets-data-model.md`.

```
oe_ticket_types   id, event_id, occurrence_id NULL (= all dates), kind ('rsvp'|'paid'),
                  name, description, price_minor, currency, capacity NULL, sold,
                  sale_start_utc, sale_end_utc, settings JSON
oe_orders         id, uuid, status, gateway, gateway_ref, email, name,
                  total_minor, currency, created_utc, meta JSON
oe_order_items    id, order_id, ticket_type_id, quantity, unit_price_minor
oe_attendees      id, uuid, order_id NULL, ticket_type_id, event_id, occurrence_id,
                  name, email, status, security_code, checked_in_utc NULL, fields JSON
```

- RSVP is a free ticket type with no order, so attendee lists, exports, emails, and check-in are written once.
- **Capacity:** `UPDATE … SET sold = sold + n WHERE id = ? AND (capacity IS NULL OR sold + n <= capacity)`. If no row changes, the type is sold out. This is atomic, unlike Event Tickets' read-modify-write meta.
- **Gateways:**

  ```php
  interface OpenEvents\Modules\Tickets\Gateway {
      public function id(): string;
      public function label(): string;
      public function is_available(): bool;
      public function start_payment( Order $order ): PaymentResult;
      public function handle_webhook( \WP_REST_Request $r ): \WP_REST_Response;
      public function refund( Order $order, int $amount_minor ): bool;
  }
  ```

  Planned order: manual/offline, then Stripe, then a WooCommerce adapter. Gateways register through `openevents/tickets/gateways`, and the site's default is an explicit choice. There are no platform fees.
- Order state lives in tables, never transients; Event Tickets' object-cache checkout bug came from transients.
- Check-in uses QR codes containing an HMAC-signed attendee UUID. Imported security codes are kept.
