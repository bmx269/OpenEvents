# Benchmark harness

Measures server time, database queries, and memory for real requests, so a site
can be compared before and after a change (or before and after a migration from
another events plugin).

- `server-timing.php`: a must-use plugin. For requests from 127.0.0.1 with the
  header `X-OE-Bench: 1`, it adds `Server-Timing` with total PHP time, query
  count and time, and peak memory. Copy it into `wp-content/mu-plugins/` on a
  local site only.
- `run.php`: requests each URL in a JSON list (warm-ups, then N runs) and
  prints medians. Run it inside the site's web container so the router isn't
  measured:

```sh
# urls.json: [{ "name": "Month view", "url": "/events/month/" }, ...]
ddev exec "BENCH_HOST=example.ddev.site php run.php urls.json 15 2 > results.json"
```

Turn off page caching for the runs, or both sides will measure the cache.
