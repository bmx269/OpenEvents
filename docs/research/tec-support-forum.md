# The Events Calendar: support forum pain points

Reviewed October 2026: 10 pages of the [TEC support forum](https://wordpress.org/support/plugin/the-events-calendar/) (290 topics), 3 pages of the Event Tickets forum (72 topics), 3 pages each of 1★ and 2★ reviews (about 150), and 26 threads read in full. Counts come from keyword-matching titles and are approximate.

| # | Theme | Topics | Root cause | How OpenEvents responds |
| --- | --- | --- | --- | --- |
| 1 | Updates break sites (fatals, "unusable after X.Y") | ~60 TEC, ~20 ET | Shared vendored "common" library and licence code running on free sites. One table name went past MySQL's 64-character limit when the site used a long prefix. | Single plugin, no licence code. Schema changes are idempotent, report through an admin notice, and never fatal (`Installer::schema_notice`). |
| 2 | Calendar views/navigation don't render | ~50 | Views V2 fetches HTML fragments over REST/AJAX, so caching, security plugins, and a disabled REST API all break it. | Every view is server-rendered with real `<a href>` links. The Interactivity API only enhances those links. Works with JS off, behind page caches, and without REST. |
| 3 | Theme/builder styling conflicts | ~27 plus reviews | Global typography and colour CSS, a forced `#tribe-events-pg-template` wrapper, inconsistent heading levels. | Blocks inherit theme.json. No global type or colour CSS, only `--oe-*` custom properties. No wrapper. Heading level is configurable. |
| 4 | Timezone/date errors | ~30 | Mixed storage modes, all-day handling lost in iCal. | UTC plus IANA timezone plus local wall-clock time per occurrence, all-day as its own flag, one formatter (`Frontend\Formatter`). |
| 5 | Crawler traps, rewrite/SEO problems | ~25 | Unbounded calendar URL space, `posts_per_page` in query strings, runtime `flush_rewrite_rules()`. | Navigation URLs are `noindex,follow` with `rel=nofollow` links. Month range is clamped to ±5 years. Rewrite rules are only flushed through a flag on activation or settings change. |
| 6 | Performance / DB bloat | ~15 plus reviews | 700+ duplicate queries, autoloaded transients with no expiry, N+1 attendee queries. | See ARCHITECTURE.md "Performance": indexed single-table reads, batch priming, object-cache only (no transients). |
| 7 | PHP deprecation log spam | ~20 | Old code on PHP 8.4. | PHP 8.2+ codebase, linted on current PHP. Zero notices is a release gate. |
| 8 | Ticketing/RSVP failures | ~55 of 72 ET topics | Order state kept in transients and broken by object caches; Tickets Commerce silently set as the default provider. | Real orders table, explicit per-site gateway choice (Phases 4–5). |
| 9 | Licensing/vendor changes, support deflection | ~14 plus many 1★ reviews | Admin menu ads, premium bugs bounced from the public forum. | GPL, no upsells, public issue tracker. |

Other notes:
- Recurring events being Pro-only is the most-cited paywall complaint. Our recurrence module will be free.
- The block editor is off by default in TEC and breaks under the iframed editor in WP 7.1 because the blocks use apiVersion 1. Ours are apiVersion 3 and iframe-safe.
- WPML causes 404s and transient bloat (about 13 topics). Multilingual support is on the roadmap. Avoid autoloaded caches.
