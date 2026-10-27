# WordPress.org and README assets

Sources for the images in `.wordpress-org/`. Not shipped in the plugin.

**Real captures** (`captures/`) come from the public demo, the same blueprint as the README's Playground link, on WordPress 7.1 with Twenty Twenty-Five at 1280×800, 2× scale (phone: 390×844, 3×):

```sh
./node_modules/.bin/wp-playground-cli server --blueprint=.wordpress-org/blueprints/blueprint.json --port=9402
node .wordpress-org-src/capture.mjs              # writes .wordpress-org-src/captures/*.png
```

Screenshots 2–6 are copies of `single`, `list`, `editor`, `setup`, and `settings`.

**Drawn images** are HTML pages rendered with Playwright (run from this folder):

```sh
node render.mjs screenshot-1.html ../.wordpress-org/screenshot-1.png 1280 800 2   # uses captures/month.png and phone.png
node render.mjs banner.html ../.wordpress-org/banner-1544x500.png 772 250 2
node render.mjs banner.html ../.wordpress-org/banner-772x250.png 772 250 1
node render.mjs icon.html ../.wordpress-org/icon-256x256.png 256 256 1
node render.mjs icon.html ../.wordpress-org/icon-128x128.png 256 256 0.5
```

Set `PLAYWRIGHT_CHROMIUM_PATH` to reuse an installed Chromium.
