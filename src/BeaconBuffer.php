<?php

namespace Bitpixel\TrafficBeacon;

use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A buffer shared by every worker, flushed to the dashboard's POST /ingest
 * endpoint at most once per configured interval.
 *
 * - On a Redis cache store the buffer is a Redis list, so recording a beacon is
 *   a single atomic RPUSH + LTRIM no matter how full the buffer is.
 * - On any other store it is an append-only spool file on the local disk: each
 *   request appends one line under a file lock, and a flush atomically renames
 *   the file away before reading it. Both are O(1) per request, so sites
 *   without Redis can run the beacon under real traffic too.
 *
 * Nothing here ever throws: the beacon must never be able to break the site.
 *
 * @phpstan-type Beacon array{method: string, status: int, path: string, duration_ms: int, ip: string|null, timestamp: int, geo?: array<string, float|string>}
 */
class BeaconBuffer
{
    private const string KEY = 'traffic-beacon:buffer';

    private const string FLUSHED_KEY = 'traffic-beacon:flushed';

    private const int TTL_SECONDS = 300;

    private const string SPOOL_FILE = 'buffer.jsonl';

    private const string FLUSHED_MARKER = 'flushed';

    /**
     * Upper bound on the spool file per buffered beacon, so a dashboard outage
     * can't fill the disk. A beacon is ~150-300 bytes as JSON.
     */
    private const int SPOOL_BYTES_PER_BEACON = 1024;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * @param  Beacon  $beacon
     */
    public function push(array $beacon): void
    {
        $this->safely(function () use ($beacon): void {
            $json = json_encode($beacon, JSON_THROW_ON_ERROR);

            if ($redis = $this->redis()) {
                $key = $this->redisKey();
                $redis->command('rpush', [$key, $json]);
                $redis->command('ltrim', [$key, -$this->maxBuffer(), -1]);
                $redis->command('expire', [$key, self::TTL_SECONDS]);

                return;
            }

            $file = $this->spoolFile();
            clearstatcache(true, $file);

            if (is_file($file) && filesize($file) >= $this->maxBuffer() * self::SPOOL_BYTES_PER_BEACON) {
                return;
            }

            file_put_contents($file, $json."\n", FILE_APPEND | LOCK_EX);
        });
    }

    /**
     * Send buffered beacons if the interval has passed since the last flush.
     */
    public function flushIfDue(): void
    {
        $this->safely(function (): void {
            $interval = max(1, (int) $this->config['flush_interval']);

            if ($this->redis()) {
                if ($this->store()->add(self::FLUSHED_KEY, true, $interval)) {
                    $this->flush();
                }

                return;
            }

            // The spool lives on this server's disk, so the "last flushed" marker does too.
            $marker = $this->spoolDirectory().'/'.self::FLUSHED_MARKER;
            clearstatcache(true, $marker);

            if (! is_file($marker) || time() - (int) filemtime($marker) >= $interval) {
                touch($marker);
                $this->flush();
            }
        });
    }

    /**
     * Send every buffered beacon now and return how many were taken from the
     * buffer. Failed batches are dropped rather than retried, so a dashboard
     * outage never slows the site down or grows the buffer without bound.
     */
    public function flush(): int
    {
        $beacons = $this->safely(fn (): array => $this->take()) ?? [];

        foreach (array_chunk($beacons, max(1, (int) $this->config['batch_size'])) as $batch) {
            $this->send($batch);
        }

        return count($beacons);
    }

    /**
     * Atomically remove and return everything in the buffer, newest last.
     *
     * @return list<array<mixed>>
     */
    private function take(): array
    {
        if ($redis = $this->redis()) {
            // LPOP with a count (Redis 6.2+) pops the whole buffer in one atomic step.
            $popped = $redis->command('lpop', [$this->redisKey(), $this->maxBuffer()]);

            return $this->decode(is_array($popped) ? $popped : []);
        }

        return array_slice($this->takeSpool(), -$this->maxBuffer());
    }

    /**
     * Claim the spool file by renaming it (atomic, so concurrent flushes can't both
     * take it), wait for any append still holding its lock, then read and delete it.
     * Claimed files abandoned by a crashed flush for over a minute are picked up too.
     *
     * @return list<array<mixed>>
     */
    private function takeSpool(): array
    {
        $directory = $this->spoolDirectory();
        $claimed = $directory.'/flushing-'.time().'-'.bin2hex(random_bytes(6)).'.jsonl';

        $files = @rename($directory.'/'.self::SPOOL_FILE, $claimed) ? [$claimed] : [];

        foreach (glob($directory.'/flushing-*.jsonl') ?: [] as $file) {
            $claimedAt = (int) explode('-', basename($file))[1];

            if ($file !== $claimed && $claimedAt < time() - 60) {
                $files[] = $file;
            }
        }

        $lines = [];

        foreach ($files as $file) {
            $handle = @fopen($file, 'r');

            if ($handle === false) {
                continue;
            }

            flock($handle, LOCK_EX);
            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($file);

            array_push($lines, ...explode("\n", (string) $contents));
        }

        return $this->decode($lines);
    }

    /**
     * @param  array<mixed>  $lines
     * @return list<array<mixed>>
     */
    private function decode(array $lines): array
    {
        $beacons = array_map(
            fn (mixed $json): mixed => is_string($json) && $json !== '' ? json_decode($json, true) : null,
            $lines,
        );

        return array_values(array_filter($beacons, 'is_array'));
    }

    /**
     * @param  list<array<mixed>>  $batch
     */
    private function send(array $batch): void
    {
        try {
            $response = Http::withToken((string) $this->config['token'])
                ->acceptJson()
                ->connectTimeout(1)
                ->timeout(max(1, (int) $this->config['timeout']))
                ->post((string) $this->config['endpoint'], ['beacons' => $batch]);

            if ($response->failed()) {
                Log::warning('Traffic beacon batch rejected by the dashboard.', [
                    'status' => $response->status(),
                    'beacons' => count($batch),
                    'hint' => $response->status() === 401 ? 'Check TRAFFIC_BEACON_TOKEN.' : null,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Traffic beacon batch could not be sent.', [
                'beacons' => count($batch),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Run a callback, swallowing (and logging) anything it throws.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult|null
     */
    private function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            Log::warning('Traffic beacon buffer error.', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    private function maxBuffer(): int
    {
        return max(1, (int) $this->config['max_buffer']);
    }

    private function spoolDirectory(): string
    {
        $directory = rtrim((string) ($this->config['spool_path'] ?? storage_path('framework/cache/traffic-beacon')), '/');

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }

    private function spoolFile(): string
    {
        return $this->spoolDirectory().'/'.self::SPOOL_FILE;
    }

    /**
     * The Redis connection behind the cache store, when it is a Redis store.
     */
    private function redis(): ?Connection
    {
        $store = $this->store()->getStore();

        return $store instanceof RedisStore ? $store->connection() : null;
    }

    private function redisKey(): string
    {
        $store = $this->store()->getStore();

        return ($store instanceof RedisStore ? $store->getPrefix() : '').self::KEY;
    }

    private function store(): Repository
    {
        return Cache::store($this->config['cache_store'] ?? null);
    }
}
