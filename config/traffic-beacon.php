<?php

return [

    /*
    | Beacons are only recorded when an endpoint and token are configured.
    | Issue a token on the dashboard with `php artisan traffic:token {site}`.
    */

    'enabled' => env('TRAFFIC_BEACON_ENABLED', true),

    'endpoint' => env('TRAFFIC_BEACON_ENDPOINT'),

    'token' => env('TRAFFIC_BEACON_TOKEN'),

    /*
    | Buffered beacons are sent at most once per interval, in batches of up to
    | `batch_size`. The buffer is capped so a dashboard outage can't grow it
    | without bound; the oldest beacons are dropped first.
    */

    'flush_interval' => (int) env('TRAFFIC_BEACON_FLUSH_INTERVAL', 5),

    'batch_size' => 500,

    'max_buffer' => 5000,

    'timeout' => 2,

    /*
    | Fraction of requests to record (1.0 = all).
    */

    'sample_rate' => (float) env('TRAFFIC_BEACON_SAMPLE_RATE', 1.0),

    /*
    | Request paths that are never reported.
    */

    'except' => [
        'up',
        '_debugbar/*',
        'horizon/*',
        'pulse*',
        'telescope/*',
    ],

    /*
    | Cache store used for the shared buffer. Use a store shared by every
    | web worker, not "array". Redis (6.2+) is strongly recommended: the
    | buffer is then a Redis list, so recording a request is O(1). Other
    | stores keep a locked array in the cache, which suits low-traffic sites.
    */

    'cache_store' => env('TRAFFIC_BEACON_CACHE_STORE'),

];
