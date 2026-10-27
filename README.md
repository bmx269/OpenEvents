# OpenEvents

Open-source events for WordPress, built to replace The Events Calendar and its paid add-ons. **OpenEvents Calendar** is the core; **RSVP**, **Tickets**, recurrence, and more are modules you switch on, with no upsells.

![OpenEvents: a month calendar on desktop that becomes an agenda on phones](.wordpress-org/screenshot-1.png)

**[Try it in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/bmx269/OpenEvents/main/.wordpress-org/blueprints/blueprint.json)** to test the plugin in your browser, with nothing to install. The demo loads sample events, venues, and coloured categories for the current month, and opens on the calendar. You're logged in as admin, so you can try the setup wizard (**Events → Settings → Run setup wizard**) and the editor too.

- **Requires:** WordPress 7.1+, PHP 8.2+
- **License:** GPLv2 or later
- **Status:** in development. The demo is rebuilt from `main` on every push.

- **Events on any post type.** Use a dedicated Events post type, add event dates to post types you already have, or both. Existing posts are never changed.
- **Fast at library scale.** Listings read one indexed table and batch-load everything they render. With 5,000 events, a month view costs 2 more queries than a plain page.
- **Works without JavaScript.** Calendar views are server-rendered links. The Interactivity API upgrades them to in-place navigation.
- **Theme-friendly.** Blocks inherit your theme.json, the templates are editable in the Site Editor, and classic themes get fallbacks.
- **Open APIs.** PHP functions, hooks, a module registry, REST, the Abilities API, Block Bindings, and editor SlotFills.

Requires WordPress 7.1+ and PHP 8.2+.

## Screenshots

| | |
| --- | --- |
| ![Event page with date, venue, organizer, cost, and calendar links](.wordpress-org/screenshot-2.png) | ![List view of past events, grouped by month](.wordpress-org/screenshot-3.png) |
| Event details: date, venue with map link, organizer, cost, and Add to Google Calendar / .ics | List view, grouped by month, with past and upcoming events |
| ![Event panel in the block editor sidebar](.wordpress-org/screenshot-4.png) | ![Setup wizard asking where events should live](.wordpress-org/screenshot-5.png) |
| Event panel in the editor: dates, timezone, venue and organizers (type a name to create one), cost | Setup wizard: a dedicated Events post type, existing post types, or both |
| ![Module settings](.wordpress-org/screenshot-6.png) | |
| Settings, built on the WordPress Design System; modules switch features on and off | |

## Status

Phase 1 (core foundation) is done. See [docs/ROADMAP.md](docs/ROADMAP.md) for what comes next.

## Development

```bash
npm install
npm run build          # or: npm start (watch)
npm run playground     # WordPress Playground with the plugin and sample events
npm run bench          # Playground with 5,000 generated events; add ?openevents_bench=1 to a page
npm run lint:js
```

### Tests

```bash
composer install && composer test:install   # one-time: WordPress + test library + SQLite drop-in in .wp-tests/
npm run test:php                            # PHPUnit integration tests, including query-count budgets
npm run test:e2e                            # Playwright against WordPress Playground
```

No Docker or MySQL is needed: PHPUnit runs WordPress on the official SQLite drop-in, and the end-to-end tests boot Playground. The same suites run in GitHub Actions on every push.

`tests/phpunit/integration/PerformanceTest.php` fails if rendering cost grows with the number of events (an N+1 query), so performance regressions are caught like any other bug.

The built `build/` directory is not committed. Run `npm run build` after cloning, or use `npm run plugin-zip` to make an installable zip.

### WP-CLI

```bash
wp openevents rebuild     # rebuild the occurrences table (after bulk imports)
wp openevents stats       # row counts and EXPLAIN plans for hot queries
wp openevents generate --count=5000   # load-test data; never on production
```

## Documentation

- [Architecture](docs/ARCHITECTURE.md): data model, performance contract, extension APIs
- [Roadmap](docs/ROADMAP.md)
- Research: [TEC support forum pain points](docs/research/tec-support-forum.md), [TEC data model](docs/research/tec-data-model.md), [Event Tickets data model](docs/research/event-tickets-data-model.md)

## Extending

```php
// Add a module that site owners can toggle in Events → Settings → Modules.
add_filter( 'openevents/modules', function ( array $modules ) {
	$modules[] = new My_Zoom_Module(); // extends OpenEvents\Modules\AbstractModule
	return $modules;
} );

// Query events anywhere.
foreach ( openevents_get_upcoming( 5, array( 'categories' => array( 12 ) ) ) as $occurrence ) {
	echo esc_html( get_the_title( $occurrence->event_id ) . ': ' . openevents_format_range( $occurrence ) );
}
```

The full list of hooks and APIs is in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#extension-apis).

## License

GPL-2.0-or-later
