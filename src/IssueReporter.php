<?php

namespace Bitpixel\TrafficBeacon;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

/**
 * Reports the exceptions the site's exception handler reports (so anything in
 * its `dontReport` list, like 404s and validation errors, is skipped too) to
 * the dashboard's POST /ingest/issues endpoint, where they are grouped into
 * issues.
 *
 * Reports wait in memory and are sent once the response has gone out (or the
 * command, or the queued job, has finished). Each kind of exception (class and
 * where it was thrown) is sent at most once per `issue_throttle` seconds; the
 * ones held back are counted and sent with the next report, so a crash on every
 * request costs one call to the dashboard, not one per request.
 *
 * The code around the app's own line closest to the throw is sent too, so an
 * exception from inside vendor/ shows the app code that led to it.
 *
 * Only the request's method, route, URL without its query string, user ID and
 * user agent are sent; never its headers, cookies, input or session. Stack
 * frames carry file, line and function, never their arguments.
 *
 * An exception the app catches and handles itself can still be sent with
 * `TrafficBeacon::report($exception)`; it is marked as handled.
 *
 * Nothing here ever throws: reporting must never be able to break the site.
 *
 * @phpstan-type Frame array{file: string|null, line: int|null, class: string|null, function: string|null, app: bool}
 * @phpstan-type Report array{class: string, message: string, code: string, file: string, line: int, trace: list<Frame>, snippet: array{file: string, line: int, start: int, lines: list<string>}|null, previous: list<array{class: string, message: string, file: string, line: int}>, handled: bool, context: array<string, mixed>, environment: array<string, string|null>, occurrences: int, timestamp: int}
 */
class IssueReporter
{
    private const string THROTTLE_KEY = 'traffic-beacon:issue:';

    private const string HELD_KEY = 'traffic-beacon:issue-held:';

    /** Different issues kept per process before the rest are dropped. */
    private const int MAX_PENDING = 20;

    private const int MAX_FRAMES = 50;

    private const int MAX_PREVIOUS = 3;

    /** Lines of source shown either side of the line that threw. */
    private const int SNIPPET_RADIUS = 6;

    /**
     * @var array<string, Report>
     */
    private array $pending = [];

    private bool $capturing = false;

    /** Set once the flush hooks are in place, so manual reports are sent too. */
    private bool $listening = false;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Application $app,
        private readonly array $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['capture_exceptions'] ?? true)
            && filled($this->config['endpoint'] ?? null)
            && filled($this->config['token'] ?? null);
    }

    /**
     * Called by the service provider once reports are flushed after each request,
     * command and queued job. Until then (in the site's own tests, or with the
     * beacon turned off) manual reports are dropped.
     */
    public function listen(): void
    {
        $this->listening = true;
    }

    /**
     * Report an exception the app caught and handled itself, like
     * `Nightwatch::report()`. Sent even when the exception handler wouldn't report
     * it, and marked as handled on the dashboard. It isn't logged.
     */
    public function report(Throwable $exception): void
    {
        if ($this->listening) {
            $this->capture($exception, handled: true);
        }
    }

    /**
     * Hold a report of the exception until the next flush. A fatal error (out of
     * memory, time limit) is sent straight away, since the app is shutting down.
     */
    public function capture(Throwable $exception, bool $handled = false): void
    {
        // An exception thrown while building a report must not be reported again.
        if ($this->capturing || ! $this->enabled()) {
            return;
        }

        $this->capturing = true;

        try {
            $this->hold($exception, $handled);

            if ($exception instanceof FatalError) {
                $this->flush();
            }
        } catch (Throwable $error) {
            Log::warning('Traffic beacon could not capture an exception.', ['error' => $error->getMessage()]);
        } finally {
            $this->capturing = false;
        }
    }

    private function hold(Throwable $exception, bool $handled): void
    {
        $file = $this->relativePath($exception->getFile());
        $key = sha1(get_class($exception).'|'.$file.':'.$exception->getLine().($handled ? '|handled' : ''));

        if (isset($this->pending[$key])) {
            $this->pending[$key]['occurrences']++;

            return;
        }

        if (count($this->pending) >= self::MAX_PENDING) {
            return;
        }

        $throttle = max(0, (int) ($this->config['issue_throttle'] ?? 10));

        if ($throttle > 0 && ! $this->store()->add(self::THROTTLE_KEY.$key, true, $throttle)) {
            // Counted, and sent along with the next report of this issue.
            $this->store()->add(self::HELD_KEY.$key, 0, 86400);
            $this->store()->increment(self::HELD_KEY.$key);

            return;
        }

        $held = $throttle > 0 ? (int) $this->store()->pull(self::HELD_KEY.$key, 0) : 0;

        $this->pending[$key] = $this->build($exception, $file) + ['handled' => $handled, 'occurrences' => 1 + $held, 'timestamp' => time()];
    }

    /**
     * Send the held reports in one request. A failure drops them.
     */
    public function flush(): int
    {
        if ($this->pending === []) {
            return 0;
        }

        $issues = array_values($this->pending);
        $this->pending = [];

        try {
            $response = Http::withToken((string) $this->config['token'])
                ->acceptJson()
                ->connectTimeout(1)
                ->timeout(max(1, (int) $this->config['timeout']))
                ->post($this->endpoint(), ['issues' => $issues]);

            if ($response->failed()) {
                Log::warning('Traffic beacon issues rejected by the dashboard.', [
                    'status' => $response->status(),
                    'issues' => count($issues),
                    'hint' => $response->status() === 401 ? 'Check TRAFFIC_BEACON_TOKEN.' : null,
                ]);

                return 0;
            }

            return count($issues);
        } catch (Throwable $exception) {
            Log::warning('Traffic beacon issues could not be sent.', [
                'issues' => count($issues),
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Reports waiting for the next flush.
     *
     * @return list<Report>
     */
    public function pending(): array
    {
        return array_values($this->pending);
    }

    /**
     * Where reports are sent: TRAFFIC_BEACON_ISSUES_ENDPOINT, or the traffic
     * endpoint with "/issues" added (https://dashboard/ingest/issues).
     */
    public function endpoint(): string
    {
        $endpoint = $this->config['issues_endpoint'] ?? null;

        return filled($endpoint) ? (string) $endpoint : rtrim((string) $this->config['endpoint'], '/').'/issues';
    }

    /**
     * @return array{class: string, message: string, code: string, file: string, line: int, trace: list<Frame>, snippet: array{file: string, line: int, start: int, lines: list<string>}|null, previous: list<array{class: string, message: string, file: string, line: int}>, context: array<string, mixed>, environment: array<string, string|null>}
     */
    private function build(Throwable $exception, string $file): array
    {
        $previous = [];

        for ($cause = $exception->getPrevious(); $cause !== null && count($previous) < self::MAX_PREVIOUS; $cause = $cause->getPrevious()) {
            $previous[] = [
                'class' => get_class($cause),
                'message' => Str::limit($cause->getMessage(), 1000, ''),
                'file' => $this->relativePath($cause->getFile()),
                'line' => $cause->getLine(),
            ];
        }

        $trace = $this->frames($exception);

        return [
            'class' => get_class($exception),
            'message' => Str::limit($exception->getMessage(), 2000, ''),
            'code' => Str::limit((string) $exception->getCode(), 50, ''),
            'file' => $file,
            'line' => $exception->getLine(),
            'trace' => $trace,
            'snippet' => $this->snippet($this->culprit($trace) ?? ['file' => $file, 'line' => $exception->getLine()]),
            'previous' => $previous,
            'context' => $this->context(),
            'environment' => [
                'app_env' => (string) $this->app->environment(),
                'php' => PHP_VERSION,
                'laravel' => $this->app->version(),
                'host' => gethostname() ?: null,
            ],
        ];
    }

    /**
     * The stack, innermost first, starting with where the exception was thrown.
     * Frames outside vendor/ are marked as the app's own.
     *
     * @return list<Frame>
     */
    private function frames(Throwable $exception): array
    {
        $trace = $exception->getTrace();

        // A trace entry's file and line are where its function was called from, so
        // the code running at each location is the next entry's function.
        $locations = [['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$trace];
        $frames = [];

        for ($index = 0; $index < min(count($locations), self::MAX_FRAMES); $index++) {
            $location = $locations[$index];
            $file = isset($location['file']) ? $this->relativePath((string) $location['file']) : null;

            $frames[] = [
                'file' => $file,
                'line' => isset($location['line']) ? (int) $location['line'] : null,
                'class' => isset($trace[$index]['class']) ? (string) $trace[$index]['class'] : null,
                'function' => isset($trace[$index]['function']) ? (string) $trace[$index]['function'] : null,
                'app' => $file !== null && ! str_starts_with($file, 'vendor/') && ! str_contains($file, '/vendor/'),
            ];
        }

        return $frames;
    }

    /**
     * The app's own frame closest to where the exception was thrown: usually the
     * line to fix, even when the exception itself came from inside vendor/. The
     * front controllers (public/index.php, artisan) don't count.
     *
     * @param  list<Frame>  $trace
     * @return array{file: string, line: int}|null
     */
    private function culprit(array $trace): ?array
    {
        foreach ($trace as $frame) {
            if ($frame['app'] && $frame['file'] !== null && $frame['line'] !== null && ! in_array($frame['file'], ['public/index.php', 'artisan'], true)) {
                return ['file' => $frame['file'], 'line' => $frame['line']];
            }
        }

        return null;
    }

    /**
     * The lines around the given line, when its file is readable.
     *
     * @param  array{file: string, line: int}  $location  The file relative to the base path.
     * @return array{file: string, line: int, start: int, lines: list<string>}|null
     */
    private function snippet(array $location): ?array
    {
        $path = str_starts_with($location['file'], '/') ? $location['file'] : $this->app->basePath($location['file']);
        $line = $location['line'];

        if ($line < 1 || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return null;
        }

        $start = max(1, $line - self::SNIPPET_RADIUS);

        return [
            'file' => $location['file'],
            'line' => $line,
            'start' => $start,
            'lines' => array_map(
                fn (string $code): string => Str::limit($code, 300, ''),
                array_slice($lines, $start - 1, self::SNIPPET_RADIUS * 2 + 1),
            ),
        ];
    }

    /**
     * What the app was doing: a routed web request, or an artisan command
     * (which is how queued jobs and the scheduler run too).
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        /** @var Request|null $request */
        $request = $this->app->bound('request') ? $this->app->make('request') : null;
        $route = $request?->route();

        if ($request === null || ($route === null && $this->app->runningInConsole())) {
            $argv = $_SERVER['argv'] ?? [];

            // Only the command's name: its arguments could hold anything.
            return ['type' => 'command', 'command' => is_array($argv) && isset($argv[1]) ? Str::limit((string) $argv[1], 200, '') : null];
        }

        return [
            'type' => 'request',
            'method' => $request->getMethod(),
            'url' => Str::limit($request->url(), 500, ''),
            'route' => is_object($route) ? '/'.ltrim($route->uri(), '/') : null,
            'route_name' => is_object($route) ? $route->getName() : null,
            'user_id' => $this->userId(),
            'user_agent' => Str::limit((string) $request->userAgent(), 300, '') ?: null,
        ];
    }

    /**
     * The signed-in user's ID, only when the app has already resolved its auth
     * guard: reporting must not start a session or query the users table.
     */
    private function userId(): ?string
    {
        if (! $this->app->resolved('auth')) {
            return null;
        }

        try {
            $guard = $this->app->make('auth')->guard();
            $id = $guard->hasUser() ? $guard->id() : null;

            return is_scalar($id) ? Str::limit((string) $id, 100, '') : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function relativePath(string $path): string
    {
        $base = rtrim($this->app->basePath(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function store(): Repository
    {
        return Cache::store($this->config['cache_store'] ?? null);
    }
}
