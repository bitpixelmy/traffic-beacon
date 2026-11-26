<?php

namespace Bitpixel\TrafficBeacon;

use Bitpixel\TrafficBeacon\Console\FlushBeacons;
use Bitpixel\TrafficBeacon\Middleware\RecordTrafficBeacon;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Installs the beacon middleware globally. Beacons are flushed from the
 * middleware once per interval; an optional scheduled flush every few seconds
 * catches the tail of the buffer when traffic goes quiet.
 *
 * Also hooks into the exception handler, so the exceptions the site reports
 * are sent to the dashboard's Issues tab once the request (or command, or
 * queued job) has finished.
 *
 * In the console, it also counts the queued jobs the workers run and reports the
 * scheduler's tasks and runs, for the dashboard's Jobs tab.
 */
class TrafficBeaconServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/traffic-beacon.php', 'traffic-beacon');

        $this->app->singleton(BeaconBuffer::class, fn ($app) => new BeaconBuffer($app['config']['traffic-beacon']));
        $this->app->singleton(IssueReporter::class, fn ($app) => new IssueReporter($app, $app['config']['traffic-beacon']));
        $this->app->singleton(JobReporter::class, fn ($app) => new JobReporter($app['config']['traffic-beacon']));
        $this->app->singleton(ScheduleReporter::class, fn ($app) => new ScheduleReporter($app, $app['config']['traffic-beacon']));
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

        $this->captureExceptions();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([FlushBeacons::class]);

        $this->trackBackgroundWork();

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

    /**
     * Count queued jobs between jobs, and report the scheduler when a task starts and
     * when `schedule:run` (or, for background tasks, `schedule:finish`) ends.
     */
    private function trackBackgroundWork(): void
    {
        $jobs = $this->app->make(JobReporter::class);

        if ($jobs->enabled()) {
            Event::listen(JobProcessing::class, [$jobs, 'starting']);
            Event::listen(JobProcessed::class, [$jobs, 'processed']);
            Event::listen(JobExceptionOccurred::class, [$jobs, 'threw']);
            Event::listen(JobFailed::class, [$jobs, 'failed']);
            Event::listen(Looping::class, fn () => $jobs->flush());
            Event::listen(WorkerStopping::class, fn () => $jobs->flush(force: true));
            $this->app->terminating(fn () => $jobs->flush(force: true));
        }

        $schedule = $this->app->make(ScheduleReporter::class);

        if ($schedule->enabled()) {
            Event::listen(ScheduledTaskStarting::class, [$schedule, 'starting']);
            Event::listen(ScheduledTaskFinished::class, [$schedule, 'finished']);
            Event::listen(ScheduledTaskFailed::class, [$schedule, 'failed']);
            Event::listen(ScheduledTaskSkipped::class, [$schedule, 'skipped']);
            Event::listen(ScheduledBackgroundTaskFinished::class, [$schedule, 'backgroundFinished']);
            Event::listen(CommandFinished::class, function (CommandFinished $event) use ($schedule): void {
                match ($event->command) {
                    'schedule:run' => $schedule->flush(),
                    'schedule:finish' => $schedule->flush(withSchedule: false),
                    default => null,
                };
            });
        }
    }

    /**
     * Report what the exception handler reports, and send it once the work is
     * done: after the response or command (terminating), between queued jobs
     * (the worker's loop), or after each Octane request.
     */
    private function captureExceptions(): void
    {
        if (! $this->app->make(IssueReporter::class)->enabled()) {
            return;
        }

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (method_exists($handler, 'reportable')) {
                // Returns nothing, so the handler still logs the exception as usual.
                $handler->reportable(function (Throwable $exception): void {
                    $this->app->make(IssueReporter::class)->capture($exception);
                });
            }
        });

        $this->app->make(IssueReporter::class)->listen();

        $flush = fn () => $this->app->make(IssueReporter::class)->flush();

        $this->app->terminating($flush);
        Event::listen([Looping::class, WorkerStopping::class, 'Laravel\\Octane\\Events\\RequestTerminated'], $flush);
    }
}
