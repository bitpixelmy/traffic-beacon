<?php

namespace Tests;

use Bitpixel\TrafficBeacon\TrafficBeaconServiceProvider;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected string $spoolPath;

    protected function setUp(): void
    {
        $this->spoolPath = sys_get_temp_dir().'/traffic-beacon-tests-'.bin2hex(random_bytes(4));

        parent::setUp();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->spoolPath);

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            TrafficBeaconServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('traffic-beacon.endpoint', 'https://dashboard.test/ingest');
        $app['config']->set('traffic-beacon.token', 'bpx_test');
        $app['config']->set('traffic-beacon.spool_path', $this->spoolPath);
        $app['config']->set('traffic-beacon.ignore_bots', false);
    }

    /**
     * @return array{method: string, status: int, path: string, duration_ms: int, ip: string|null, timestamp: int}
     */
    protected function beacon(string $path = '/'): array
    {
        return ['method' => 'GET', 'status' => 200, 'path' => $path, 'duration_ms' => 12, 'ip' => '203.0.113.7', 'timestamp' => time()];
    }
}
