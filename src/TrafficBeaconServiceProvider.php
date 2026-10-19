<?php

namespace Bitpixel\TrafficBeacon;

use Bitpixel\TrafficBeacon\Console\FlushBeacons;
use Bitpixel\TrafficBeacon\Middleware\RecordTrafficBeacon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;

/**
 * Installs the beacon middleware globally. Beacons are flushed from the
 * middleware once per interval; an optional scheduled flush every few seconds
 * catches the tail of the buffer when traffic goes quiet.
 */
class TrafficBeaconServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/traffic-beacon.php', 'traffic-beacon');

        $this->app->singleton(BeaconBuffer::class, fn ($app) => new BeaconBuffer($app['config']['traffic-beacon']));
        $this->app->singleton(RecordTrafficBeacon::class, fn ($app) => new RecordTrafficBeacon(
            $app->make(BeaconBuffer::class),
            $app['config']['traffic-beacon'],
        ));
    }

    public function boot(): void
    {
        // Publishable even while the beacon is off, so a fresh install can be configured.
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/traffic-beacon.php' => config_path('traffic-beacon.php'),
            ], 'traffic-beacon-config');
        }

        // Never record the host app's own test runs.
        if (! config('traffic-beacon.enabled') || $this->app->runningUnitTests()) {
            return;
        }

        $this->app->make(Kernel::class)->pushMiddleware(RecordTrafficBeacon::class);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([FlushBeacons::class]);

        if (config('traffic-beacon.schedule_flush')) {
            // Runs inside the long-lived `schedule:run` process rather than as an
            // artisan command, which would boot the whole app 12 times a minute.
            // The lock lasts a minute: the default 24 hours would stop flushing
            // for a day if a flush is killed.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->call(fn (BeaconBuffer $buffer) => $buffer->flush())
                    ->name('traffic-beacon:flush')
                    ->everyFiveSeconds()
                    ->withoutOverlapping(1);
            });
        }
    }
}
