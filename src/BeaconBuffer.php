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
 * - On a Redis cache store the buffer is a Redis list. Recording and taking
 *   beacons are each one Lua script, so they are atomic, cost one round trip
 *   and work on any Redis version.
 * - On any other store it is an append-only spool file on the local disk: each
 *   request appends one line under a file lock, and a flush atomically renames
 *   the file away before reading it. When the spool fills up it is moved aside
 *   as the overflow file, replacing the previous one, so the oldest beacons are
 *   dropped first. Both are O(1) per request, so sites without Redis can run
 *   the beacon under real traffic too.
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

    private const string OVERFLOW_FILE = 'overflow.jsonl';

    private const int APPEND_ATTEMPTS = 5;

    private const string FLUSHED_MARKER = 'flushed';

    /**
     * Size of a full spool file per buffered beacon. With the overflow file the
     * spool takes at most twice this, so a dashboard outage can't fill the disk.
     * A beacon is ~150-300 bytes as JSON.
     */
    private const int SPOOL_BYTES_PER_BEACON = 1024;

    /**
     * Append a beacon, keep only the newest ARGV[2] and refresh the TTL.
     */
    private const string PUSH_SCRIPT = <<<'LUA'
        redis.call('rpush', KEYS[1], ARGV[1])
        redis.call('ltrim', KEYS[1], -tonumber(ARGV[2]), -1)
        redis.call('expire', KEYS[1], tonumber(ARGV[3]))
        return 1
        LUA;

    /**
     * Pop up to ARGV[1] beacons from the head of the list in one atomic step.
     * Unlike LPOP with a count, this needs neither Redis 6.2 nor a client that
     * passes the count through.
     */
    private const string TAKE_SCRIPT = <<<'LUA'
        local items = redis.call('lrange', KEYS[1], 0, tonumber(ARGV[1]) - 1)
        redis.call('ltrim', KEYS[1], #items, -1)
        return items
        LUA;

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
                $redis->eval(self::PUSH_SCRIPT, 1, $this->redisKey(), $json, $this->maxBuffer(), self::TTL_SECONDS);

                return;
            }

            $this->appendToSpool($json."\n");
        });
    }

    /**
     * Append a line to the spool under an exclusive lock. A flush (or an overflow
     * rotation) can rename the file between our fopen and flock, and a line
     * written then would land in a file that has already been read. So once the
     * lock is held, the handle must still be the file at the spool path;
     * otherwise it is reopened.
     */
    private function appendToSpool(string $line): void
    {
        $file = $this->spoolFile();
        $directory = $this->spoolDirectory();

        for ($attempt = 0; $attempt < self::APPEND_ATTEMPTS; $attempt++) {
            $handle = fopen($file, 'a');

            if ($handle === false) {
                return;
            }

            try {
                $this->lockSpool($handle);
                clearstatcache(true, $file);
                $atPath = @stat($file);
                $locked = fstat($handle);

                if ($atPath === false || $atPath['ino'] !== $locked['ino'] || $atPath['dev'] !== $locked['dev']) {
                    continue;
                }

                if ($locked['size'] >= $this->maxBuffer() * self::SPOOL_BYTES_PER_BEACON) {
                    rename($file, $directory.'/'.self::OVERFLOW_FILE);

                    continue;
                }

                fwrite($handle, $line);

                return;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /**
     * @param  resource  $handle
     */
    protected function lockSpool($handle): void
    {
        flock($handle, LOCK_EX);
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
     * Send every buffered beacon now. Failed batches are dropped rather than
     * retried, and the first failure drops the rest of this flush too, so a
     * dashboard outage costs one request's timeout per flush, not one per batch.
     */
    public function flush(): FlushResult
    {
        $beacons = $this->safely(fn (): array => $this->take()) ?? [];
        $sent = 0;

        foreach (array_chunk($beacons, max(1, (int) $this->config['batch_size'])) as $batch) {
            if (! $this->send($batch)) {
                if ($skipped = count($beacons) - $sent - count($batch)) {
                    Log::warning('Traffic beacon skipped the rest of a flush after a failed batch.', ['beacons' => $skipped]);
                }

                break;
            }

            $sent += count($batch);
        }

        return new FlushResult(taken: count($beacons), sent: $sent);
    }

    /**
     * Atomically remove and return everything in the buffer, newest last.
     *
     * @return list<array<mixed>>
     */
    private function take(): array
    {
        if ($redis = $this->redis()) {
            $popped = $redis->eval(self::TAKE_SCRIPT, 1, $this->redisKey(), $this->maxBuffer());

            return $this->decode(is_array($popped) ? $popped : []);
        }

        return array_slice($this->takeSpool(), -$this->maxBuffer());
    }

    /**
     * Claim the overflow and spool files by renaming them (atomic, so concurrent
     * flushes can't both take one), wait for any append still holding a lock,
     * then read and delete them, oldest first. Claimed files abandoned by a
     * crashed flush for over a minute are picked up too.
     *
     * @return list<array<mixed>>
     */
    private function takeSpool(): array
    {
        $directory = $this->spoolDirectory();
        $claimId = time().'-'.bin2hex(random_bytes(6));
        $claimed = [];

        foreach ([self::OVERFLOW_FILE, self::SPOOL_FILE] as $index => $name) {
            $claim = "{$directory}/flushing-{$claimId}-{$index}.jsonl";

            if (@rename("{$directory}/{$name}", $claim)) {
                $claimed[] = $claim;
            }
        }

        $abandoned = array_filter(
            glob($directory.'/flushing-*.jsonl') ?: [],
            fn (string $file): bool => ! in_array($file, $claimed, true)
                && (int) explode('-', basename($file))[1] < time() - 60,
        );
        sort($abandoned);

        $files = [...$abandoned, ...$claimed];
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
    private function send(array $batch): bool
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

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning('Traffic beacon batch could not be sent.', [
                'beacons' => count($batch),
                'error' => $exception->getMessage(),
            ]);

            return false;
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
