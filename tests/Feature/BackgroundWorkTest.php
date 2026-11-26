<?php

use Bitpixel\TrafficBeacon\JobReporter;
use Bitpixel\TrafficBeacon\ScheduleReporter;
use Bitpixel\TrafficBeacon\TrafficBeaconServiceProvider;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Boot the provider as it boots in a deployed site, with fresh reporters.
 */
function bootWorkInProduction(): void
{
    app()->forgetInstance(JobReporter::class);
    app()->forgetInstance(ScheduleReporter::class);
    app()->instance('env', 'production');
    (new TrafficBeaconServiceProvider(app()))->boot();
}

/**
 * A queued job as a worker sees it.
 */
function workerJob(string $class = 'App\\Jobs\\SendInvoice', int $waitedSeconds = 2): FakeJob
{
    return new class($class, $waitedSeconds) extends FakeJob
    {
        public function __construct(private string $class, private int $waitedSeconds) {}

        public function getRawBody()
        {
            return json_encode(['displayName' => $this->class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'createdAt' => time() - $this->waitedSeconds]);
        }

        public function getQueue()
        {
            return 'mail';
        }

        public function getConnectionName()
        {
            return 'redis';
        }
    };
}

/**
 * Run the scheduler as `php artisan schedule:run` does, which reports when the command
 * finishes (Artisan::call() doesn't raise that event).
 */
function runScheduler(): void
{
    Artisan::call('schedule:run');
    event(new CommandFinished('schedule:run', new ArrayInput([]), new NullOutput, 0));
}

/**
 * @return list<array<string, mixed>>
 */
function sentSchedules(): array
{
    return Http::recorded(fn (ClientRequest $request) => str_ends_with($request->url(), '/ingest/schedule'))
        ->map(fn (array $pair): array => $pair[0]->data())
        ->values()
        ->all();
}

beforeEach(function () {
    Http::fake(['dashboard.test/*' => Http::response(['accepted' => 1], 202)]);
});

it('counts the jobs a worker runs per job, queue and minute, and sends them when it stops', function () {
    bootWorkInProduction();

    $done = workerJob();
    event(new JobProcessing('redis', $done));
    event(new JobProcessed('redis', $done));

    $retried = workerJob();
    event(new JobProcessing('redis', $retried));
    event(new JobExceptionOccurred('redis', $retried, new RuntimeException('Timed out')));

    $failed = workerJob();
    event(new JobProcessing('redis', $failed));
    $failed->fail(new RuntimeException('Card declined'));
    event(new JobFailed('redis', $failed, new RuntimeException('Card declined')));

    event(new JobProcessing('sync', $sync = workerJob('App\\Jobs\\Inline')));
    event(new JobProcessed('sync', $sync));

    event(new WorkerStopping(0));

    Http::assertSent(function (ClientRequest $request): bool {
        $jobs = $request['jobs'];

        return $request->url() === 'https://dashboard.test/ingest/jobs'
            && $request->hasHeader('Authorization', 'Bearer bpx_test')
            && count($jobs) === 1
            && $jobs[0]['job'] === 'App\\Jobs\\SendInvoice'
            && $jobs[0]['queue'] === 'mail'
            && $jobs[0]['minute'] % 60 === 0
            && [$jobs[0]['processed'], $jobs[0]['released'], $jobs[0]['failed']] === [1, 1, 1]
            && $jobs[0]['wait_ms_max'] >= 2000;
    });
});

it('sends job counts between jobs at most once per flush interval', function () {
    bootWorkInProduction();
    $reporter = app(JobReporter::class);

    event(new JobProcessing('redis', $job = workerJob()));
    event(new JobProcessed('redis', $job));

    expect($reporter->flush())->toBe(0)->and($reporter->pending())->toHaveCount(1);

    $this->travel(6)->seconds();
    expect($reporter->flush(force: true))->toBe(1);
});

it('reports the schedule, each task\'s run and a failure when schedule:run ends', function () {
    $schedule = app(Schedule::class);
    $schedule->call(fn () => null)->everyMinute()->name('orders:sync');
    $schedule->call(fn () => throw new RuntimeException('Disk full'))->hourly()->name('backup:run');
    $schedule->call(fn () => null)->everyMinute()->environments('local')->name('local-only');
    bootWorkInProduction();
    $this->travelTo(now()->startOfHour());

    runScheduler();

    $reports = sentSchedules();
    $final = end($reports);
    // Each task's outcome, sent with the next task's start or when the scheduler ends.
    $runs = collect($reports)->flatMap(fn (array $report) => $report['runs'])
        ->reject(fn (array $run): bool => $run['status'] === 'started')
        ->keyBy(fn (array $run): string => collect($final['tasks'])->firstWhere('key', $run['key'])['name']);

    expect(collect($final['tasks'])->pluck('name')->all())->toBe(['orders:sync', 'backup:run'])
        ->and($final['tasks'][1])->toMatchArray(['expression' => '0 * * * *', 'timezone' => 'UTC', 'repeat_seconds' => null, 'background' => false])
        ->and($runs['orders:sync']['status'])->toBe('success')
        ->and($runs['backup:run'])->toMatchArray(['status' => 'failed', 'error' => 'RuntimeException: Disk full'])
        ->and(collect($reports)->flatMap(fn (array $report) => $report['runs'])->where('status', 'started'))->toHaveCount(2)
        ->and($final['scheduler']['maintenance'])->toBeFalse();
});

it('reports nothing about jobs or the schedule when turned off', function () {
    config()->set('traffic-beacon.track_jobs', false);
    config()->set('traffic-beacon.track_schedule', false);
    app(Schedule::class)->call(fn () => null)->everyMinute()->name('orders:sync');
    bootWorkInProduction();

    event(new JobProcessing('redis', $job = workerJob()));
    event(new JobProcessed('redis', $job));
    event(new WorkerStopping(0));
    runScheduler();

    Http::assertNothingSent();
});
