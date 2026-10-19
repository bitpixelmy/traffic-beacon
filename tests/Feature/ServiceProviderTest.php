<?php

use Bitpixel\TrafficBeacon\BeaconBuffer;
use Bitpixel\TrafficBeacon\Middleware\RecordTrafficBeacon;
use Bitpixel\TrafficBeacon\TrafficBeaconServiceProvider;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Boot the provider as it boots in a deployed site, where the app isn't
 * running unit tests.
 */
function bootInProduction(): void
{
    app()->instance('env', 'production');
    (new TrafficBeaconServiceProvider(app()))->boot();
}

function scheduledFlush(): ?CallbackEvent
{
    return collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'traffic-beacon:flush');
}

it('does not schedule a flush by default', function () {
    bootInProduction();

    expect(scheduledFlush())->toBeNull()
        ->and(Artisan::all())->toHaveKey('traffic-beacon:flush');
});

it('schedules the flush in-process with a one-minute overlap lock when turned on', function () {
    config()->set('traffic-beacon.schedule_flush', true);
    bootInProduction();

    $event = scheduledFlush();

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->command)->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(1)
        ->and($event->isRepeatable())->toBeTrue()
        ->and($event->repeatSeconds)->toBe(5);
});

it('sends buffered beacons when the scheduled flush runs', function () {
    Http::fake(['dashboard.test/*' => Http::response(['ok' => true])]);
    config()->set('traffic-beacon.schedule_flush', true);
    bootInProduction();
    app(BeaconBuffer::class)->push($this->beacon());

    scheduledFlush()->run(app());

    Http::assertSentCount(1);
});

it('registers the global middleware when enabled', function () {
    bootInProduction();

    expect(app(Kernel::class)->hasMiddleware(RecordTrafficBeacon::class))->toBeTrue();
});

it('registers nothing when disabled', function () {
    config()->set('traffic-beacon.enabled', false);
    bootInProduction();

    expect(app(Kernel::class)->hasMiddleware(RecordTrafficBeacon::class))->toBeFalse();
});

it('registers nothing while the host app runs its tests', function () {
    expect(app(Kernel::class)->hasMiddleware(RecordTrafficBeacon::class))->toBeFalse();
});

it('publishes the config', function () {
    expect(TrafficBeaconServiceProvider::pathsToPublish(TrafficBeaconServiceProvider::class, 'traffic-beacon-config'))
        ->toHaveCount(1);
});
