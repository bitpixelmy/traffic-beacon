# BitPixel Traffic Beacon

Reports anonymised request beacons from a Laravel site to the BitPixel Deployment Monitor, so its visitors show up live on the dashboard's globe. It also reports the site's crashes (exceptions) to the dashboard's **Issues** tab.

- **Global middleware**: it registers itself, so no code changes are needed.
- **Reports the matched route pattern** (e.g. `/posts/{post}`), the status, the duration and the visitor IP. It never sends the concrete URL, query strings or IDs. The dashboard uses the IP to look up a city and shows it in its incoming feed; it keeps each request for 2 days, then deletes it.
- **Runs after the response is sent** and never throws, so it can't slow down or break the site.
- **Batches beacons** and sends them at most every 5 seconds.
- **Reports exceptions** the site's exception handler reports, with their stack trace, to the Issues tab. See [Crash reporting](#crash-reporting).

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
composer require bitpixel/traffic-beacon:^0.2
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
TRAFFIC_BEACON_SCHEDULE_FLUSH=false
TRAFFIC_BEACON_SAMPLE_RATE=1.0
TRAFFIC_BEACON_IGNORE_BOTS=true
TRAFFIC_BEACON_IP_HEADER=CF-Connecting-IP
TRAFFIC_BEACON_CACHE_STORE=redis
TRAFFIC_BEACON_SPOOL_PATH=/path/to/spool
TRAFFIC_BEACON_CAPTURE_EXCEPTIONS=true
TRAFFIC_BEACON_ISSUES_ENDPOINT=
TRAFFIC_BEACON_ISSUE_THROTTLE=10
```

| Env | Default | Purpose |
| --- | --- | --- |
| `TRAFFIC_BEACON_ENABLED` | `true` | Turn the beacon on or off without uninstalling it. Required. |
| `TRAFFIC_BEACON_ENDPOINT` | — | The dashboard's `/ingest` URL. Required. |
| `TRAFFIC_BEACON_TOKEN` | — | The site's ingest token. Required. |
| `TRAFFIC_BEACON_FLUSH_INTERVAL` | `5` | Seconds between sends. |
| `TRAFFIC_BEACON_SCHEDULE_FLUSH` | `false` | Also flush every 5 seconds from the scheduler. See below. |
| `TRAFFIC_BEACON_SAMPLE_RATE` | `1.0` | Fraction of requests to record. |
| `TRAFFIC_BEACON_IGNORE_BOTS` | `true` | Skip bots and requests without a user agent. |
| `TRAFFIC_BEACON_IP_HEADER` | — | Header with the real visitor IP, e.g. `CF-Connecting-IP`. |
| `TRAFFIC_BEACON_CACHE_STORE` | default store | The store checked for Redis. |
| `TRAFFIC_BEACON_SPOOL_PATH` | `storage/framework/cache/traffic-beacon` | Where the spool file lives when Redis isn't used. |
| `TRAFFIC_BEACON_CAPTURE_EXCEPTIONS` | `true` | Send the site's exceptions to the Issues tab. |
| `TRAFFIC_BEACON_ISSUES_ENDPOINT` | endpoint + `/issues` | Where exceptions are sent. |
| `TRAFFIC_BEACON_ISSUE_THROTTLE` | `10` | Seconds between reports of the same exception. `0` sends every one. |

### Scheduled flush (optional)

The scheduler isn't needed. Each request sends the buffer once `TRAFFIC_BEACON_FLUSH_INTERVAL` has passed since the last send, so on a quiet site a visit is usually sent straight away. Beacons only wait when they arrive within a few seconds of a send. They then go out with the next request after the interval. With Redis, they're lost if no request comes for 5 minutes.

To send those too, set `TRAFFIC_BEACON_SCHEDULE_FLUSH=true` and keep the site's scheduler running (`php artisan schedule:run` every minute). The beacon then flushes every 5 seconds from the scheduler. A task that repeats more often than once a minute keeps `schedule:run` running for the whole minute instead of exiting, which is why this is off by default. With spool files on several servers, it only flushes the server that runs the scheduler.

You can also send the buffer by hand with `php artisan traffic-beacon:flush`.

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

## Crash reporting

Every exception the site's exception handler reports is also sent to the dashboard, where the **Issues** tab groups them by kind (the exception's class and where it was thrown), per site, with how often each happened. It uses the same endpoint and token as the traffic beacon, so a site that already sends traffic needs no new settings.

- **Only what the site would log.** Exceptions in the handler's `dontReport` list (404s, validation errors, expired sessions and so on) are skipped, and so is anything `report()` would skip.
- **Sent after the work is done:** after the response has gone out, after an artisan command, or between a queue worker's jobs. Fatal errors (out of memory, time limit) are sent straight away.
- **What's sent:** the exception's class, message, code, file and line, up to 50 stack frames (file, line and function, never their arguments), the lines of code around the app's own line closest to the throw (so an exception from inside `vendor/` shows the app code that led to it), up to 3 previous exceptions, and the PHP and Laravel versions, environment and hostname. For a request: its method, route, route name, URL **without the query string**, the signed-in user's ID and the user agent. For a command: its name, without arguments. Headers, cookies, input and the session are never sent.
- **A crash storm costs one call.** The same exception is sent at most once every `TRAFFIC_BEACON_ISSUE_THROTTLE` seconds; the repeats in between are counted and sent with the next report, so the dashboard's occurrence counts stay right.
- **It never breaks the site.** Reporting can't throw, and if the dashboard is down the reports are dropped.

Set `TRAFFIC_BEACON_CAPTURE_EXCEPTIONS=false` to keep the traffic beacon but stop sending exceptions. Turning the beacon off (`TRAFFIC_BEACON_ENABLED=false`) stops both.

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

### v0.2.0
- **Crash reporting:** exceptions the site reports are sent to the dashboard's new Issues tab, with their stack trace and the request's route and URL (without the query string). On by default; turn it off with `TRAFFIC_BEACON_CAPTURE_EXCEPTIONS=false`. Needs a dashboard that has the Issues tab; an older one turns the reports away, which only logs a warning.

### v0.1.5
- **The scheduled flush is now optional and off by default.** Set `TRAFFIC_BEACON_SCHEDULE_FLUSH=true` to turn it back on. Each request already sends the buffer once the flush interval has passed, so the scheduler only catches the last few beacons before traffic goes quiet. Turning it off also stops `schedule:run` from staying alive for the whole minute. The `traffic-beacon:flush` command is still available.

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
