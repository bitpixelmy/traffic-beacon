<?php

return [

    /*
    | Turn the beacon on or off for this site without uninstalling it:
    | TRAFFIC_BEACON_ENABLED=true|false (also accepts on/off, yes/no, 1/0).
    | Beacons are only recorded when an endpoint and token are configured too.
    | Issue a token on the dashboard under Manage → Sites.
    */

    'enabled' => filter_var(env('TRAFFIC_BEACON_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'endpoint' => env('TRAFFIC_BEACON_ENDPOINT'),

    'token' => env('TRAFFIC_BEACON_TOKEN'),

    /*
    | Buffered beacons are sent at most once per interval, in batches of up to
    | `batch_size`. The buffer is capped so a dashboard outage can't grow it
    | without bound; the oldest beacons are dropped first.
    */

    'flush_interval' => (int) env('TRAFFIC_BEACON_FLUSH_INTERVAL', 5),

    'batch_size' => 500,

    /*
    | Also flush every few seconds from the site's scheduler, so the last
    | beacons before traffic goes quiet aren't left waiting for the next
    | request: TRAFFIC_BEACON_SCHEDULE_FLUSH=true|false. Off by default,
    | because a task that repeats more often than once a minute keeps
    | `schedule:run` running for the whole minute. Needs the scheduler.
    */

    'schedule_flush' => filter_var(env('TRAFFIC_BEACON_SCHEDULE_FLUSH', false), FILTER_VALIDATE_BOOLEAN),

    'max_buffer' => 5000,

    'timeout' => 2,

    /*
    | Fraction of requests to record (1.0 = all).
    */

    'sample_rate' => (float) env('TRAFFIC_BEACON_SAMPLE_RATE', 1.0),

    /*
    | Request paths that are never reported: health checks, dev tools and
    | background requests that aren't page views (Livewire updates, Flux and
    | Livewire assets, broadcasting auth, Sanctum's CSRF cookie).
    */

    'except' => [
        'up',
        '_debugbar/*',
        'horizon/*',
        'pulse*',
        'telescope/*',
        'livewire/*',
        'livewire-*',
        'flux/*',
        'broadcasting/*',
        'sanctum/csrf-cookie',
    ],

    /*
    | Skip crawlers, link previewers, uptime monitors and HTTP libraries
    | (matched by user agent), and requests with no user agent at all.
    */

    'ignore_bots' => (bool) env('TRAFFIC_BEACON_IGNORE_BOTS', true),

    /*
    | Header holding the real visitor IP when the site sits behind a proxy
    | that Laravel doesn't trust, e.g. "CF-Connecting-IP" behind Cloudflare.
    | Leave empty to use $request->ip(). Only set it when every request comes
    | through that proxy: otherwise visitors can forge the header.
    */

    'ip_header' => env('TRAFFIC_BEACON_IP_HEADER'),

    /*
    | Where beacons wait between flushes. When this cache store is Redis
    | (6.2+), the buffer is a Redis list. Otherwise beacons are appended to a
    | spool file in `spool_path` on the local disk. Both cost O(1) per request,
    | so Redis is optional.
    */

    'cache_store' => env('TRAFFIC_BEACON_CACHE_STORE'),

    'spool_path' => env('TRAFFIC_BEACON_SPOOL_PATH', storage_path('framework/cache/traffic-beacon')),

];
