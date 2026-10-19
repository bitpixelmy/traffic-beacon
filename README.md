# BitPixel Traffic Beacon

Reports anonymised request beacons from a Laravel site to the BitPixel Deployment Monitor, so its visitors show up live on the dashboard's globe.

- **Global middleware**: it registers itself, so no code changes are needed.
- **Reports the matched route pattern** (e.g. `/posts/{post}`), the status, the duration and the visitor IP. It never sends the concrete URL, query strings or IDs. The dashboard uses the IP to look up a city and shows it in its incoming feed; it keeps each request for 2 days, then deletes it.
- **Runs after the response is sent** and never throws, so it can't slow down or break the site.
- **Batches beacons** and sends them at most every 5 seconds.

## Requirements

- PHP 8.3 or higher
- Laravel 12 or 13
- An ingest token for the site, issued on the dashboard

## Install

### 1. Add the repository

The package isn't on Packagist, so tell Composer where to find it. In the consuming Laravel project's `composer.json`, add:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/bitpixelmy/traffic-beacon.git"
        }
    ]
}
```

Or run:

```bash
composer config repositories.traffic-beacon vcs https://github.com/bitpixelmy/traffic-beacon.git
```

### 2. Install the package

```bash
composer require bitpixel/traffic-beacon:^0.1
```

The repository is public, so no GitHub token or Composer authentication is needed.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=traffic-beacon-config
```

This creates `config/traffic-beacon.php` in the consuming app, where you can also change the excluded paths, batch size and buffer cap.

In the dashboard, go to **Manage → Sites**, open the site and click **Issue token**. Copy the `.env` lines it shows; the token is shown once. Then add these values to the consuming Laravel app's `.env` file:

```env
TRAFFIC_BEACON_ENABLED=true
TRAFFIC_BEACON_ENDPOINT=https://your-dashboard-domain.com/ingest
TRAFFIC_BEACON_TOKEN=bpx_…
```

Optional settings:

```env
TRAFFIC_BEACON_FLUSH_INTERVAL=5
TRAFFIC_BEACON_SAMPLE_RATE=1.0
TRAFFIC_BEACON_IGNORE_BOTS=true
TRAFFIC_BEACON_IP_HEADER=CF-Connecting-IP
TRAFFIC_BEACON_CACHE_STORE=redis
TRAFFIC_BEACON_SPOOL_PATH=/path/to/spool
```

| Env | Default | Purpose |
| --- | --- | --- |
| `TRAFFIC_BEACON_ENABLED` | `true` | Turn the beacon on or off without uninstalling it. Required. |
| `TRAFFIC_BEACON_ENDPOINT` | — | The dashboard's `/ingest` URL. Required. |
| `TRAFFIC_BEACON_TOKEN` | — | The site's ingest token. Required. |
| `TRAFFIC_BEACON_FLUSH_INTERVAL` | `5` | Seconds between sends. |
| `TRAFFIC_BEACON_SAMPLE_RATE` | `1.0` | Fraction of requests to record. |
| `TRAFFIC_BEACON_IGNORE_BOTS` | `true` | Skip bots and requests without a user agent. |
| `TRAFFIC_BEACON_IP_HEADER` | — | Header with the real visitor IP, e.g. `CF-Connecting-IP`. |
| `TRAFFIC_BEACON_CACHE_STORE` | default store | The store checked for Redis. |
| `TRAFFIC_BEACON_SPOOL_PATH` | `storage/framework/cache/traffic-beacon` | Where the spool file lives when Redis isn't used. |

Keep the site's scheduler running (`php artisan schedule:run` every minute). It sends the last few beacons when traffic goes quiet.

## Turning it on or off

Set `TRAFFIC_BEACON_ENABLED=false` in the site's `.env` to stop reporting, and `true` to start again. It also accepts `on`/`off`, `yes`/`no` and `1`/`0`. When off, the middleware isn't registered at all, so the beacon adds no work to requests.

The change takes effect on the next request. If the site caches its config (`php artisan config:cache`, which Forge runs on deploy), re-run that command or redeploy.

## Where beacons wait (Redis is optional)

| Site's cache store | Buffer | Notes |
| --- | --- | --- |
| Redis | A Redis list | Shared by all servers behind a load balancer. Set `TRAFFIC_BEACON_CACHE_STORE=redis` if Redis isn't the default store. |
| Anything else | A spool file on local disk | `storage/framework/cache/traffic-beacon`. One appended line per request. Each server flushes its own file. |

Both cost O(1) per request. If the dashboard is down, batches are dropped rather than retried, and the buffer is capped at `max_buffer` beacons.

## Real visitor IPs behind a proxy

The beacon uses `$request->ip()`, which is only the visitor's IP when Laravel trusts the proxy in front of the site. Otherwise every visitor appears to come from the proxy and lands in the wrong city on the globe.

- **Cloudflare**, either option:
  - Trust Cloudflare's IPs in the site, e.g. with a package like `monicahq/laravel-cloudflare`, or with `trustProxies(at: [...])` in `bootstrap/app.php`.
  - Or set `TRAFFIC_BEACON_IP_HEADER=CF-Connecting-IP`.

  Also turn on **Rules → Managed Transforms → Add visitor location headers**. The beacon then sends Cloudflare's own city and coordinates, and no IP lookup is needed. These headers are only used when the request came from a proxy Laravel trusts, or when `TRAFFIC_BEACON_IP_HEADER=CF-Connecting-IP`, so visitors can't fake them.
- **Load balancer or another proxy:** trust it with `trustProxies(at: '…')`, or set `TRAFFIC_BEACON_IP_HEADER=X-Forwarded-For`. The first address in the header is used.

> Only set `TRAFFIC_BEACON_IP_HEADER` when **every** request reaches the site through that proxy. Otherwise visitors can send the header themselves and fake their location.

## What isn't recorded

- **Bots:** crawlers, link previewers (Facebook, WhatsApp, Slack and others), uptime monitors, Lighthouse, headless browsers, HTTP libraries such as curl, Guzzle and python-requests, and requests with no user agent. Set `TRAFFIC_BEACON_IGNORE_BOTS=false` to include them.
- **Background requests:** Livewire updates (`livewire/*`, `livewire-*`), Flux assets, broadcasting auth and Sanctum's CSRF cookie.
- **Tools:** health checks (`/up`), Horizon, Pulse, Telescope and Debugbar.
- **Odd methods:** HTTP methods other than GET, HEAD, POST, PUT, PATCH, DELETE and OPTIONS.

To change the exclusions, edit the `except` list in the published `config/traffic-beacon.php`.

## Laravel Octane

The beacon also works under Laravel Octane: request duration is measured per request, not from when the worker started.

## Testing This Package

Inside this package repo:

```bash
composer install
vendor/bin/pest
```

The Redis tests run when a Redis server is reachable on `127.0.0.1:6379` (or `REDIS_HOST`/`REDIS_PORT`) and are skipped otherwise.

## License

Proprietary. This package is only for BitPixel's own sites. The repository is public so sites can install it without a GitHub token, but that doesn't grant a licence to use it anywhere else. See [LICENSE](LICENSE).

## Changelog

### v0.1.4
- **Supports Guzzle 8:** the package now allows `guzzlehttp/guzzle` `^7.8|^8.0`, so Laravel 13 sites can use Guzzle 8. Laravel 12 sites keep Guzzle 7.

### v0.1.3
- **A dashboard outage no longer ties up a worker:** a flush stops at the first failed batch instead of waiting out a timeout for each one.
- **The scheduled flush can't get stuck:** its overlap lock now expires after a minute, not 24 hours.
- **Redis buffer works on any Redis version and client:** pushing and taking beacons are single atomic Lua scripts, so Redis 6.2 is no longer needed.
- **Cloudflare location headers are only trusted when the request really came through Cloudflare**, so visitors can't fake their location.
- **`traffic-beacon:flush` reports dropped beacons** and exits with a failure when the dashboard rejects them.
- **No more lost beacons in the spool file:** an append that a flush overtakes between opening and locking the file now retries on the new file instead of writing to one that has already been sent.
- **A full spool drops the oldest beacons, not the newest:** it is moved aside as an overflow file, so it uses at most twice the cap on disk.
- **The scheduled flush is much cheaper:** it runs inside `schedule:run` instead of starting the app as an artisan command 12 times a minute.
- **Requires `laravel/framework` and `guzzlehttp/guzzle`** instead of separate `illuminate/*` packages, matching what the package actually uses.

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
