<?php

namespace Bitpixel\TrafficBeacon;

use Bitpixel\TrafficBeacon\Concerns\SendsToDashboard;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reports the site's scheduler to the dashboard's POST /ingest/schedule: that it ran,
 * the tasks in its schedule (name, cron expression, timezone), and how each task it
 * ran went, so the dashboard can tell a failed task, a missed one, or a scheduler that
 * stopped. Sent when a task starts (so a long task isn't taken for missed) and when
 * `schedule:run` ends; a task run in the background reports its exit code from
 * `schedule:finish`.
 *
 * A task repeating within the minute (everyFiveSeconds()) is reported once per
 * minute, with how many times it ran. Only the task's name, schedule, timings, exit
 * code and the failing exception's class and message are sent, never its output.
 *
 * Nothing here ever throws: reporting must never be able to break the scheduler.
 *
 * @phpstan-type Run array{key: string, status: string, started_at?: int, duration_ms?: int, exit_code?: int|null, runs?: int, error?: string|null}
 */
class ScheduleReporter
{
    use SendsToDashboard;

    /** The beacon's own flush task, which isn't the site's. */
    private const string OWN_TASK = 'traffic-beacon:flush';

    /**
     * @var array<string, Run>
     */
    private array $pending = [];

    /**
     * When each task under way started, by its key.
     *
     * @var array<string, float>
     */
    private array $started = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Application $app,
        private readonly array $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['track_schedule'] ?? true) && $this->hasDashboard();
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->tracks($event->task)) {
                return;
            }

            $key = $this->key($event->task);
            $this->started[$key] ??= microtime(true);

            // Reported straight away, so a task that runs long isn't taken for missed.
            if (! $event->task->isRepeatable()) {
                $this->pending[$key] = ['key' => $key, 'status' => 'started', 'started_at' => (int) $this->started[$key]];
                $this->flush();
            }
        });
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->guard(function () use ($event): void {
            // A background task reports its exit code later, from schedule:finish.
            if (! $this->tracks($event->task) || $event->task->runInBackground) {
                return;
            }

            $this->record($event->task, $event->task->exitCode === null || (int) $event->task->exitCode === 0 ? 'success' : 'failed', (int) round($event->runtime * 1000));
        });
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->tracks($event->task)) {
                return;
            }

            $key = $this->key($event->task);

            if (! isset($this->pending[$key]) || $this->pending[$key]['status'] === 'started') {
                $this->record($event->task, 'failed', null);
            }

            $this->pending[$key]['status'] = 'failed';
            $this->pending[$key]['error'] = Str::limit(class_basename($event->exception).': '.$event->exception->getMessage(), 1000, '');
        });
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->guard(function () use ($event): void {
            // The server that won the task reports its run.
            $reason = $event->reason ?? null;

            if (! $this->tracks($event->task) || ($reason instanceof \UnitEnum && $reason->name === 'RanOnAnotherServer')) {
                return;
            }

            $this->record($event->task, 'skipped', 0);
        });
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->tracks($event->task)) {
                $key = $this->key($event->task);
                $this->pending[$key] = ['key' => $key, 'status' => 'finished', 'exit_code' => $event->task->exitCode === null ? null : (int) $event->task->exitCode];
            }
        });
    }

    /**
     * Send what's waiting, with the tasks in the schedule and that the scheduler ran.
     */
    public function flush(bool $withSchedule = true): bool
    {
        try {
            $payload = [
                'scheduler' => ['timestamp' => time(), 'maintenance' => $this->app->isDownForMaintenance()],
                'tasks' => $withSchedule ? $this->tasks() : [],
                'runs' => array_values($this->pending),
            ];
        } catch (Throwable) {
            return false;
        }

        $this->pending = [];

        return $this->send($this->endpointFor('schedule_endpoint', 'schedule'), $payload, 'schedule');
    }

    /**
     * Runs waiting for the next send.
     *
     * @return list<Run>
     */
    public function pending(): array
    {
        return array_values($this->pending);
    }

    /**
     * The tasks in the schedule that run in this environment.
     *
     * @return list<array{key: string, name: string, expression: string, timezone: string, repeat_seconds: int|null, background: bool}>
     */
    public function tasks(): array
    {
        $tasks = [];

        foreach ($this->app->make(Schedule::class)->events() as $task) {
            if (! $this->tracks($task) || ! $task->runsInEnvironment($this->app->environment())) {
                continue;
            }

            $tasks[] = [
                'key' => $this->key($task),
                'name' => $this->name($task),
                'expression' => Str::limit((string) $task->expression, 100, ''),
                'timezone' => $this->timezone($task),
                'repeat_seconds' => $task->isRepeatable() ? (int) $task->repeatSeconds : null,
                'background' => (bool) $task->runInBackground,
            ];
        }

        return $tasks;
    }

    /**
     * @param  'success'|'failed'|'skipped'  $status
     */
    private function record(Event $task, string $status, ?int $durationMs): void
    {
        $key = $this->key($task);
        $startedAt = (int) ($this->started[$key] ?? time());
        unset($this->started[$key]);

        $previous = $this->pending[$key] ?? null;

        // Repeats within the minute add up into one run.
        if ($task->isRepeatable() && $previous !== null && $previous['status'] !== 'started') {
            $this->pending[$key]['runs'] = ($previous['runs'] ?? 1) + 1;
            $this->pending[$key]['duration_ms'] = ($previous['duration_ms'] ?? 0) + (int) $durationMs;

            if ($status === 'failed') {
                $this->pending[$key]['status'] = 'failed';
                $this->pending[$key]['exit_code'] = $task->exitCode === null ? null : (int) $task->exitCode;
            }

            return;
        }

        $this->pending[$key] = [
            'key' => $key,
            'status' => $status,
            'started_at' => $previous['started_at'] ?? $startedAt,
            'duration_ms' => (int) $durationMs,
            'exit_code' => $task->exitCode === null ? null : (int) $task->exitCode,
            'runs' => 1,
            'error' => null,
        ];
    }

    private function tracks(Event $task): bool
    {
        return $task->description !== self::OWN_TASK;
    }

    /**
     * The same task keeps its key while its name and schedule stay the same.
     */
    private function key(Event $task): string
    {
        return sha1($this->name($task).'|'.$task->expression.'|'.($task->isRepeatable() ? $task->repeatSeconds : ''));
    }

    /**
     * Its description, or the command without the PHP binary and artisan in front.
     */
    private function name(Event $task): string
    {
        $name = is_string($task->description) && $task->description !== ''
            ? $task->description
            : (string) preg_replace('/^.*?[\'"]?artisan[\'"]?\s+/', '', (string) $task->getSummaryForDisplay());

        return Str::limit(trim($name) ?: 'Callback', 255, '');
    }

    private function timezone(Event $task): string
    {
        $timezone = $task->timezone ?? $this->app['config']->get('app.timezone', 'UTC');

        return $timezone instanceof \DateTimeZone ? $timezone->getName() : (string) $timezone;
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Never let reporting break the scheduler.
        }
    }
}
