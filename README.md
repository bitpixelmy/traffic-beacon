# BitPixel Traffic Beacon

Reports anonymised request beacons from a Laravel site to the BitPixel Deployment Monitor, so its visitors show up live on the dashboard's globe.

- **Global middleware**: it registers itself, so no code changes are needed.
- **Reports the matched route pattern** (e.g. `/posts/{post}`), the status, the duration and the visitor IP. It never sends the concrete URL, query strings or IDs. The IP is only used by the dashboard to look up a city and is not stored.
- **Runs after the response is sent** and never throws, so it can't slow down or break the site.
- **Batches beacons** and sends them at most every 5 seconds.

Requires PHP 8.3+ and Laravel 12 or 13.

## Install

1. In the dashboard, go to **Manage → Sites**, open the site and click **Issue token**. Copy the `.env` lines it shows; the token is shown once.
2. In the site's project, add the private repository and require the package:

   ```bash
   composer config repositories.traffic-beacon vcs git@github.com:bitpixelmy/traffic-beacon.git
   composer require bitpixel/traffic-beacon:^0.1
   ```

   The server that runs `composer install` needs read access to `bitpixelmy/traffic-beacon`, e.g. a deploy key or a GitHub token.

3. Add to the site's `.env` and deploy:

   ```dotenv
   TRAFFIC_BEACON_ENABLED=true
   TRAFFIC_BEACON_ENDPOINT=https://deployment.bitpixel.app/ingest
   TRAFFIC_BEACON_TOKEN=bpx_…
   ```

4. Keep the site's scheduler running (`php artisan schedule:run` every minute). It sends the last few beacons when traffic goes quiet.

## Turning it on or off

Set `TRAFFIC_BEACON_ENABLED=false` in the site's `.env` to stop reporting, and `true` to start again. It also accepts `on`/`off`, `yes`/`no` and `1`/`0`. When off, the middleware isn't registered at all, so the beacon adds no work to requests.

The change takes effect on the next request. If the site caches its config (`php artisan config:cache`, which Forge runs on deploy), re-run that command or redeploy.

## Where beacons wait (Redis is optional)

| Site's cache store | Buffer | Notes |
| --- | --- | --- |
| Redis 6.2+ | A Redis list | Shared by all servers behind a load balancer. Set `TRAFFIC_BEACON_CACHE_STORE=redis` if Redis isn't the default store. |
| Anything else | A spool file on local disk | `storage/framework/cache/traffic-beacon`. One appended line per request. Each server flushes its own file. |

Both cost O(1) per request. If the dashboard is down, batches are dropped rather than retried, and the buffer is capped at `max_buffer` beacons.

## Real visitor IPs behind a proxy

The beacon uses `$request->ip()`, which is only the visitor's IP when Laravel trusts the proxy in front of the site. Otherwise every visitor appears to come from the proxy and lands in the wrong city on the globe.

- **Cloudflare**, either option:
  - Trust Cloudflare's IPs in the site, e.g. with a package like `monicahq/laravel-cloudflare`, or with `trustProxies(at: [...])` in `bootstrap/app.php`.
  - Or set `TRAFFIC_BEACON_IP_HEADER=CF-Connecting-IP`.

  Also turn on **Rules → Managed Transforms → Add visitor location headers**. The beacon then sends Cloudflare's own city and coordinates, and no IP lookup is needed.
- **Load balancer or another proxy:** trust it with `trustProxies(at: '…')`, or set `TRAFFIC_BEACON_IP_HEADER=X-Forwarded-For`. The first address in the header is used.

> Only set `TRAFFIC_BEACON_IP_HEADER` when **every** request reaches the site through that proxy. Otherwise visitors can send the header themselves and fake their location.

## What isn't recorded

- **Bots:** crawlers, link previewers (Facebook, WhatsApp, Slack and others), uptime monitors, Lighthouse, headless browsers, HTTP libraries such as curl, Guzzle and python-requests, and requests with no user agent. Set `TRAFFIC_BEACON_IGNORE_BOTS=false` to include them.
- **Background requests:** Livewire updates (`livewire/*`, `livewire-*`), Flux assets, broadcasting auth and Sanctum's CSRF cookie.
- **Tools:** health checks (`/up`), Horizon, Pulse, Telescope and Debugbar.
- **Odd methods:** HTTP methods other than GET, HEAD, POST, PUT, PATCH, DELETE and OPTIONS.

To change the exclusions, publish the config:

```bash
php artisan vendor:publish --tag=traffic-beacon-config
```

## Configuration

| Env | Default | Purpose |
| --- | --- | --- |
| `TRAFFIC_BEACON_ENDPOINT` | — | The dashboard's `/ingest` URL. Required. |
| `TRAFFIC_BEACON_TOKEN` | — | The site's ingest token. Required. |
| `TRAFFIC_BEACON_ENABLED` | `true` | Turn the beacon on or off without uninstalling it. |
| `TRAFFIC_BEACON_FLUSH_INTERVAL` | `5` | Seconds between sends. |
| `TRAFFIC_BEACON_SAMPLE_RATE` | `1.0` | Fraction of requests to record. |
| `TRAFFIC_BEACON_IGNORE_BOTS` | `true` | Skip bots and requests without a user agent. |
| `TRAFFIC_BEACON_IP_HEADER` | — | Header with the real visitor IP, e.g. `CF-Connecting-IP`. |
| `TRAFFIC_BEACON_CACHE_STORE` | default store | The store checked for Redis. |
| `TRAFFIC_BEACON_SPOOL_PATH` | `storage/framework/cache/traffic-beacon` | Where the spool file lives when Redis isn't used. |

The beacon also works under Laravel Octane: request duration is measured per request, not from when the worker started.

## Changelog

### v0.1.2
- **`TRAFFIC_BEACON_ENABLED` accepts more values:** `on`/`off`, `yes`/`no` and `1`/`0`, not just `true`/`false`. Anything unrecognised turns it off.

### v0.1.1
- **Without Redis**, beacons now go to an append-only spool file instead of a locked array in the cache, so sites without Redis can handle real traffic.
- **New `TRAFFIC_BEACON_IP_HEADER` option** for the real visitor IP behind Cloudflare or a load balancer.
- **Bots, uptime monitors and HTTP libraries are skipped by default**, and so are Livewire update requests, Flux assets, broadcasting auth and Sanctum's CSRF cookie.
- **Request duration is correct under Laravel Octane.**
- **Upgrading:** sites that published `config/traffic-beacon.php` should republish it, or add the new `except` entries and the new keys by hand.

### v0.1.0
- First release.
