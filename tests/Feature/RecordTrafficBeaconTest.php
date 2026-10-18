<?php

use Bitpixel\TrafficBeacon\BeaconBuffer;
use Bitpixel\TrafficBeacon\Middleware\RecordTrafficBeacon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * Run a request through the middleware and return the beacon it sent. A fresh
 * spool has never been flushed, so the first beacon is sent straight away.
 *
 * @param  array<string, string>  $headers
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>|null
 */
function recordBeacon(array $headers = [], array $config = [], string $remoteAddr = '198.51.100.1'): ?array
{
    $config = array_merge(config('traffic-beacon'), $config);
    $middleware = new RecordTrafficBeacon(new BeaconBuffer($config), $config);

    $server = ['REMOTE_ADDR' => $remoteAddr, 'HTTP_USER_AGENT' => 'Mozilla/5.0'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $request = Request::create('/posts/42', 'GET', server: $server);
    $response = $middleware->handle($request, fn () => new Response('ok'));
    $middleware->terminate($request, $response);

    return Http::recorded()->last()[0]['beacons'][0] ?? null;
}

const CLOUDFLARE_GEO = [
    'CF-IPLatitude' => '3.139',
    'CF-IPLongitude' => '101.6869',
    'CF-IPCity' => 'Kuala Lumpur',
    'CF-IPCountry' => 'MY',
];

beforeEach(function () {
    Http::fake();
});

afterEach(function () {
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

it('ignores Cloudflare location headers from a client that did not come through Cloudflare', function () {
    $beacon = recordBeacon(CLOUDFLARE_GEO);

    expect($beacon)->not->toBeNull()->not->toHaveKey('geo');
});

it('forwards Cloudflare location from a trusted proxy', function () {
    Request::setTrustedProxies(['198.51.100.1'], Request::HEADER_X_FORWARDED_FOR);

    expect(recordBeacon(CLOUDFLARE_GEO)['geo'])->toBe([
        'latitude' => 3.139,
        'longitude' => 101.6869,
        'city' => 'Kuala Lumpur',
        'country' => 'MY',
    ]);
});

it('forwards Cloudflare location when the site says every request comes via Cloudflare', function () {
    $beacon = recordBeacon(
        [...CLOUDFLARE_GEO, 'CF-Connecting-IP' => '203.0.113.9'],
        ['ip_header' => 'cf-connecting-ip'],
    );

    expect($beacon['geo']['city'])->toBe('Kuala Lumpur')
        ->and($beacon['ip'])->toBe('203.0.113.9');
});

it('does not forward Cloudflare location for other IP headers', function () {
    $beacon = recordBeacon(
        [...CLOUDFLARE_GEO, 'X-Forwarded-For' => '203.0.113.9'],
        ['ip_header' => 'X-Forwarded-For'],
    );

    expect($beacon)->not->toHaveKey('geo');
});

it('reports an unmatched request without its URL', function () {
    expect(recordBeacon()['path'])->toBe('unmatched');
});
