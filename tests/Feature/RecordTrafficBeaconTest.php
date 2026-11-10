<?php

use Bitpixel\TrafficBeacon\BeaconBuffer;
use Bitpixel\TrafficBeacon\Middleware\RecordTrafficBeacon;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
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
function recordBeacon(array $headers = [], array $config = [], string $remoteAddr = '198.51.100.1', string $uri = '/posts/42', ?string $route = null): ?array
{
    $config = array_merge(config('traffic-beacon'), $config);
    $middleware = new RecordTrafficBeacon(new BeaconBuffer($config), $config);

    $server = ['REMOTE_ADDR' => $remoteAddr, 'HTTP_USER_AGENT' => 'Mozilla/5.0'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $request = Request::create($uri, 'GET', server: $server);

    if ($route !== null) {
        $matched = (new Route(['GET'], $route, fn () => null))->bind($request);
        $request->setRouteResolver(fn () => $matched);
    }
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

it('does not report the signed-in user unless user tracking is on', function () {
    Auth::guard('web')->setUser(new GenericUser(['id' => 7, 'name' => 'Aisyah Rahman']));

    expect(recordBeacon())->not->toHaveKeys(['user', 'url']);
});

it('reports the user signed in on any guard in auth.php when user tracking is on', function () {
    config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users']]);
    Auth::guard('admin')->setUser(new GenericUser(['id' => 42, 'name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'username' => 'aisyah', 'password' => 'secret']));

    expect(recordBeacon(config: ['users' => ['enabled' => true, 'guards' => []]], route: 'posts/{post}'))
        ->user->toBe(['guard' => 'admin', 'id' => '42', 'name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'username' => 'aisyah'])
        ->url->toBe('/posts/42');
});

it('only checks the listed guards, and reports no user for a guest', function () {
    config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users']]);
    Auth::guard('admin')->setUser(new GenericUser(['id' => 42]));

    expect(recordBeacon(config: ['users' => ['enabled' => true, 'guards' => ['web']]]))->not->toHaveKeys(['user', 'url']);
});

it('turns user tracking on from TRAFFIC_BEACON_TRACK_USERS, off by default', function () {
    expect(config('traffic-beacon.users'))->toBe(['enabled' => false, 'guards' => []]);
});

it('reports the real path of every request, without the query string, only when real URLs are on', function () {
    expect(config('traffic-beacon.real_urls'))->toBeFalse()
        ->and(recordBeacon(config: ['real_urls' => true], uri: '/posts/42?token=secret', route: 'posts/{post}'))
        ->path->toBe('/posts/{post}')
        ->url->toBe('/posts/42')
        ->not->toHaveKey('user');
});

it('keeps secret route parameters as placeholders, and sends no real path for unmatched requests', function (string $uri, ?string $route, ?string $url) {
    $beacon = recordBeacon(config: ['real_urls' => true], uri: $uri, route: $route);

    expect($beacon['url'] ?? null)->toBe($url);
})->with([
    'password reset' => ['/reset-password/abc123', 'reset-password/{token}', '/reset-password/{token}'],
    'email verification' => ['/email/verify/7/f00d', 'email/verify/{id}/{hash}', '/email/verify/7/{hash}'],
    'optional parameter left out' => ['/docs', 'docs/{page?}', '/docs'],
    'no route matched' => ['/wp-login.php', null, null],
]);
