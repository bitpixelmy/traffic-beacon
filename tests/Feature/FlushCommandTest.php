<?php

use Bitpixel\TrafficBeacon\BeaconBuffer;
use Bitpixel\TrafficBeacon\Console\FlushBeacons;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // The provider skips everything during the host app's test runs, so
    // register what it would in production.
    $this->app->make(Kernel::class)->registerCommand($this->app->make(FlushBeacons::class));
});

it('reports how many beacons were sent', function () {
    Http::fake(['dashboard.test/*' => Http::response(['ok' => true])]);
    app(BeaconBuffer::class)->push($this->beacon());

    $this->artisan('traffic-beacon:flush')
        ->expectsOutputToContain('Sent 1 beacons.')
        ->assertSuccessful();
});

it('fails and reports dropped beacons when the dashboard rejects them', function () {
    Http::fake(['dashboard.test/*' => Http::response('nope', 401)]);
    app(BeaconBuffer::class)->push($this->beacon());
    app(BeaconBuffer::class)->push($this->beacon());

    $this->artisan('traffic-beacon:flush')
        ->expectsOutputToContain('Sent 0 beacons, dropped 2.')
        ->assertFailed();
});

it('succeeds when there is nothing to send', function () {
    Http::fake();

    $this->artisan('traffic-beacon:flush')->assertSuccessful();

    Http::assertNothingSent();
});
