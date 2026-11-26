<?php

namespace Bitpixel\TrafficBeacon;

use Bitpixel\TrafficBeacon\Concerns\SendsToDashboard;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Str;
use Throwable;

/**
 * Counts the queued jobs the site's workers run, per job class, queue and minute: how
 * many finished, failed for good, or failed and were released to try again, how long
 * they took and how long they waited in the queue. Counts wait in memory and are sent
 * to the dashboard's POST /ingest/jobs between jobs, at most once per
 * `flush_interval` seconds, and when the worker stops.
 *
 * Only the job's class, queue and timings are sent, never its payload. Jobs run on
 * the sync connection aren't counted: they run inside the request that dispatched them.
 *
 * Nothing here ever throws: reporting must never be able to break a job.
 *
 * @phpstan-type Count array{minute: int, job: string, queue: string, processed: int, failed: int, released: int, duration_ms_sum: int, duration_ms_max: int, wait_ms_sum: int, wait_ms_max: int}
 */
class JobReporter
{
    use SendsToDashboard;

    /** Different job and minute rows kept before the oldest are sent. */
    private const int MAX_PENDING = 500;

    /**
     * @var array<string, Count>
     */
    private array $pending = [];

    /**
     * When each job under way started (hrtime, in nanoseconds) and how long it waited.
     *
     * @var array<string, array{started: int, wait_ms: int}>
     */
    private array $running = [];

    private float $lastFlush;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config)
    {
        $this->lastFlush = microtime(true);
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['track_jobs'] ?? true) && $this->hasDashboard();
    }

    public function starting(JobProcessing $event): void
    {
        $this->guard(function () use ($event): void {
            if ($event->connectionName === 'sync') {
                return;
            }

            $createdAt = $event->job->payload()['createdAt'] ?? null;

            $this->running[$this->id($event->job)] = [
                'started' => hrtime(true),
                'wait_ms' => is_int($createdAt) ? max(0, (time() - $createdAt) * 1000) : 0,
            ];
        });
    }

    public function processed(JobProcessed $event): void
    {
        $this->guard(function () use ($event): void {
            // A job that failed itself was counted by its failed event.
            if (! $event->job->hasFailed()) {
                $this->count($event->job, $event->job->isReleased() ? 'released' : 'processed');
            }
        });
    }

    /**
     * A job that threw: released to try again, or failed for good (counted by the failed event).
     */
    public function threw(JobExceptionOccurred $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $event->job->hasFailed()) {
                $this->count($event->job, 'released');
            }
        });
    }

    public function failed(JobFailed $event): void
    {
        $this->guard(fn () => $this->count($event->job, 'failed'));
    }

    /**
     * Send the counts when the interval has passed since the last send, or now.
     */
    public function flush(bool $force = false): int
    {
        if ($this->pending === [] || (! $force && microtime(true) - $this->lastFlush < max(1, (int) ($this->config['flush_interval'] ?? 5)))) {
            return 0;
        }

        $jobs = array_values($this->pending);
        $this->pending = [];
        $this->lastFlush = microtime(true);

        return $this->send($this->endpointFor('jobs_endpoint', 'jobs'), ['jobs' => $jobs], 'jobs') ? count($jobs) : 0;
    }

    /**
     * Counts waiting for the next send.
     *
     * @return list<Count>
     */
    public function pending(): array
    {
        return array_values($this->pending);
    }

    /**
     * @param  'processed'|'failed'|'released'  $outcome
     */
    private function count(Job $job, string $outcome): void
    {
        $id = $this->id($job);
        $run = $this->running[$id] ?? null;
        unset($this->running[$id]);

        // Started before the beacon was listening, or on the sync connection.
        if ($run === null) {
            return;
        }

        $duration = (int) round((hrtime(true) - $run['started']) / 1_000_000);
        $name = Str::limit($job->resolveName(), 255, '');
        $queue = Str::limit((string) ($job->getQueue() ?: 'default'), 100, '');
        $minute = intdiv(time(), 60) * 60;
        $key = $minute.'|'.$name.'|'.$queue;

        if (! isset($this->pending[$key])) {
            if (count($this->pending) >= self::MAX_PENDING) {
                $this->flush(force: true);
            }

            $this->pending[$key] = ['minute' => $minute, 'job' => $name, 'queue' => $queue, 'processed' => 0, 'failed' => 0, 'released' => 0, 'duration_ms_sum' => 0, 'duration_ms_max' => 0, 'wait_ms_sum' => 0, 'wait_ms_max' => 0];
        }

        $this->pending[$key][$outcome]++;
        $this->pending[$key]['duration_ms_sum'] += $duration;
        $this->pending[$key]['duration_ms_max'] = max($this->pending[$key]['duration_ms_max'], $duration);
        $this->pending[$key]['wait_ms_sum'] += $run['wait_ms'];
        $this->pending[$key]['wait_ms_max'] = max($this->pending[$key]['wait_ms_max'], $run['wait_ms']);
    }

    private function id(Job $job): string
    {
        return $job->getConnectionName().'|'.($job->uuid() ?? $job->getJobId()).'|'.$job->attempts();
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Never let counting break a job.
        }
    }
}
