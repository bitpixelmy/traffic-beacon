<?php

namespace Bitpixel\TrafficBeacon;

use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockProvider;
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
 * On a Redis cache store the buffer is a Redis list, so recording a beacon is a
 * single atomic RPUSH + LTRIM no matter how full the buffer is. On any other
 * store it falls back to a locked array in the cache, which is fine for low
 * traffic sites.
 *
 * Nothing here ever throws: the beacon must never be able to break the site.
 *
 * @phpstan-type Beacon array{method: string, status: int, path: string, duration_ms: int, ip: string|null, timestamp: int, geo?: array<string, float|string>}
 */
class BeaconBuffer
{
    private const string KEY = 'traffic-beacon:buffer';

    private const string LOCK = 'traffic-beacon:lock';

    private const string FLUSHED_KEY = 'traffic-beacon:flushed';

    private const int TTL_SECONDS = 300;

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
            $maxBuffer = max(1, (int) $this->config['max_buffer']);

            if ($redis = $this->redis()) {
                $key = $this->redisKey();
                $redis->command('rpush', [$key, json_encode($beacon, JSON_THROW_ON_ERROR)]);
                $redis->command('ltrim', [$key, -$maxBuffer, -1]);
                $redis->command('expire', [$key, self::TTL_SECONDS]);

                return;
            }

            $this->withLock(function () use ($beacon, $maxBuffer): void {
                $buffer = $this->cachedBuffer();
                $buffer[] = $beacon;

                $this->store()->put(self::KEY, array_slice($buffer, -$maxBuffer), self::TTL_SECONDS);
            });
        });
    }

    /**
     * Send buffered beacons if the interval has passed since the last flush.
     */
    public function flushIfDue(): void
    {
        $this->safely(function (): void {
            if ($this->store()->add(self::FLUSHED_KEY, true, max(1, (int) $this->config['flush_interval']))) {
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
     * Atomically remove and return everything in the buffer.
     *
     * @return list<array<mixed>>
     */
    private function take(): array
    {
        if ($redis = $this->redis()) {
            // LPOP with a count (Redis 6.2+) pops the whole buffer in one atomic step.
            $popped = $redis->command('lpop', [$this->redisKey(), max(1, (int) $this->config['max_buffer'])]);

            $beacons = array_map(
                fn (mixed $json): mixed => is_string($json) ? json_decode($json, true) : null,
                is_array($popped) ? $popped : [],
            );

            return array_values(array_filter($beacons, 'is_array'));
        }

        return $this->withLock(function (): array {
            $buffer = $this->cachedBuffer();
            $this->store()->forget(self::KEY);

            return $buffer;
        });
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

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function withLock(callable $callback): mixed
    {
        $store = $this->store()->getStore();

        return $store instanceof LockProvider
            ? $store->lock(self::LOCK, 5)->block(2, $callback)
            : $callback();
    }

    /**
     * @return list<array<mixed>>
     */
    private function cachedBuffer(): array
    {
        $buffer = $this->store()->get(self::KEY, []);

        return is_array($buffer) ? array_values(array_filter($buffer, 'is_array')) : [];
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
