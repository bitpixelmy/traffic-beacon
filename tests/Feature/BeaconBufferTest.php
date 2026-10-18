<?php

use Bitpixel\TrafficBeacon\BeaconBuffer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function buffer(array $overrides = []): BeaconBuffer
{
    return new BeaconBuffer(array_merge(config('traffic-beacon'), $overrides));
}

it('sends spooled beacons in batches', function () {
    Http::fake(['dashboard.test/*' => Http::response(['ok' => true])]);
    $buffer = buffer(['batch_size' => 2]);

    foreach (range(1, 5) as $i) {
        $buffer->push($this->beacon("/page-{$i}"));
    }

    $result = $buffer->flush();

    expect($result->taken)->toBe(5)
        ->and($result->sent)->toBe(5)
        ->and($result->dropped())->toBe(0);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer bpx_test')
        && $request['beacons'][0]['path'] === '/page-1');
});

it('stops a flush at the first failed batch so an outage costs one timeout', function () {
    Http::fake(['dashboard.test/*' => Http::response('down', 503)]);
    $buffer = buffer(['batch_size' => 2]);

    foreach (range(1, 6) as $i) {
        $buffer->push($this->beacon());
    }

    $result = $buffer->flush();

    expect($result->taken)->toBe(6)
        ->and($result->sent)->toBe(0)
        ->and($result->dropped())->toBe(6);

    Http::assertSentCount(1);
});

it('stops a flush when the dashboard cannot be reached', function () {
    Http::fake(['dashboard.test/*' => Http::failedConnection()]);
    Log::spy();
    $buffer = buffer(['batch_size' => 1]);

    foreach (range(1, 3) as $i) {
        $buffer->push($this->beacon());
    }

    expect($buffer->flush()->sent)->toBe(0);

    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')->with('Traffic beacon skipped the rest of a flush after a failed batch.', ['beacons' => 2]);
});

it('counts the batches sent before a failure', function () {
    Http::fakeSequence('dashboard.test/*')->push(['ok' => true])->push('down', 503);
    $buffer = buffer(['batch_size' => 2]);

    foreach (range(1, 5) as $i) {
        $buffer->push($this->beacon());
    }

    $result = $buffer->flush();

    expect($result->sent)->toBe(2)->and($result->dropped())->toBe(3);
    Http::assertSentCount(2);
});

it('empties the spool once flushed, even when sending fails', function () {
    Http::fake(['dashboard.test/*' => Http::response('down', 503)]);
    $buffer = buffer();
    $buffer->push($this->beacon());

    expect($buffer->flush()->taken)->toBe(1)
        ->and($buffer->flush()->taken)->toBe(0);
});

it('drops the oldest beacons when the spool is full', function () {
    Http::fake(['dashboard.test/*' => Http::response(['ok' => true])]);
    // A full spool file is max_buffer KB; each beacon here pads to ~1 KB, so
    // the spool rotates to the overflow file every 3 beacons.
    $buffer = buffer(['max_buffer' => 3]);
    $padding = str_repeat('x', 900);

    foreach (range(1, 10) as $i) {
        $buffer->push([...$this->beacon("/page-{$i}"), 'pad' => $padding]);
    }

    expect(glob($this->spoolPath.'/*.jsonl'))->toHaveCount(2);

    $result = $buffer->flush();

    expect($result->sent)->toBe(3);
    Http::assertSent(fn (Request $request) => array_column($request['beacons'], 'path') === ['/page-8', '/page-9', '/page-10']);
});

it('picks up a spool file abandoned by a crashed flush', function () {
    Http::fake(['dashboard.test/*' => Http::response(['ok' => true])]);
    mkdir($this->spoolPath, 0775, true);
    file_put_contents($this->spoolPath.'/flushing-'.(time() - 120).'-abc-1.jsonl', json_encode($this->beacon('/old'))."\n");
    $buffer = buffer();
    $buffer->push($this->beacon('/new'));

    expect($buffer->flush()->sent)->toBe(2);
    Http::assertSent(fn (Request $request) => array_column($request['beacons'], 'path') === ['/old', '/new']);
});

it('loses no beacon when a flush takes the spool between opening and locking it', function () {
    Http::fake(['dashboard.test/*' => Http::response(['ok' => true])]);
    $config = config('traffic-beacon');
    $flusher = buffer();

    // An appender that is overtaken by a flush right after opening the spool.
    $appender = new class($config, $flusher) extends BeaconBuffer
    {
        public int $flushedMeanwhile = 0;

        public function __construct(array $config, private readonly BeaconBuffer $flusher)
        {
            parent::__construct($config);
        }

        protected function lockSpool($handle): void
        {
            if ($this->flushedMeanwhile === 0) {
                $this->flushedMeanwhile = $this->flusher->flush()->taken;
            }

            parent::lockSpool($handle);
        }
    };

    $flusher->push($this->beacon('/first'));
    $appender->push($this->beacon('/second'));

    expect($appender->flushedMeanwhile)->toBe(1)
        ->and($flusher->flush()->sent)->toBe(1);

    Http::assertSent(fn (Request $request) => array_column($request['beacons'], 'path') === ['/second']);
});

describe('on Redis', function () {
    beforeEach(function () {
        if (! extension_loaded('redis') && ! class_exists(\Predis\Client::class)) {
            $this->markTestSkipped('Neither phpredis nor Predis is installed.');
        }

        if (! redisAvailable()) {
            $this->markTestSkipped('No Redis server on '.env('REDIS_HOST', '127.0.0.1').':'.env('REDIS_PORT', 6379).'.');
        }

        config()->set('cache.stores.redis.connection', 'default');
        config()->set('cache.prefix', 'traffic-beacon-tests:');
        config()->set('traffic-beacon.cache_store', 'redis');
        cache()->store('redis')->getStore()->connection()->command('del', ['traffic-beacon-tests:traffic-beacon:buffer']);
    });

    it('pushes and takes beacons atomically, oldest first', function () {
        Http::fake(['dashboard.test/*' => Http::response(['ok' => true])]);
        $buffer = buffer();

        foreach (range(1, 3) as $i) {
            $buffer->push($this->beacon("/page-{$i}"));
        }

        expect($buffer->flush()->sent)->toBe(3)
            ->and($buffer->flush()->taken)->toBe(0);

        Http::assertSent(fn (Request $request) => array_column($request['beacons'], 'path') === ['/page-1', '/page-2', '/page-3']);
    });

    it('keeps only the newest max_buffer beacons', function () {
        Http::fake(['dashboard.test/*' => Http::response(['ok' => true])]);
        $buffer = buffer(['max_buffer' => 2]);

        foreach (range(1, 5) as $i) {
            $buffer->push($this->beacon("/page-{$i}"));
        }

        expect($buffer->flush()->sent)->toBe(2);

        Http::assertSent(fn (Request $request) => array_column($request['beacons'], 'path') === ['/page-4', '/page-5']);
    });

    it('takes no more than max_buffer beacons per flush', function () {
        Http::fake(['dashboard.test/*' => Http::response(['ok' => true])]);
        buffer(['max_buffer' => 10])->push($this->beacon());
        buffer(['max_buffer' => 10])->push($this->beacon());

        expect(buffer(['max_buffer' => 1])->flush()->taken)->toBe(1)
            ->and(buffer()->flush()->taken)->toBe(1);
    });
});
