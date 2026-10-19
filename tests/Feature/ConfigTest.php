<?php

use Bitpixel\TrafficBeacon\TrafficBeaconServiceProvider;

/**
 * Load the package config with the given environment variable set.
 */
function configWithEnv(string $name, ?string $value): array
{
    $value === null ? putenv($name) : putenv("{$name}={$value}");

    try {
        return require dirname((new ReflectionClass(TrafficBeaconServiceProvider::class))->getFileName()).'/../config/traffic-beacon.php';
    } finally {
        putenv($name);
    }
}

it('turns the scheduled flush off by default', function () {
    expect(configWithEnv('TRAFFIC_BEACON_SCHEDULE_FLUSH', null)['schedule_flush'])->toBeFalse();
});

it('reads TRAFFIC_BEACON_SCHEDULE_FLUSH as a boolean', function (string $value, bool $expected) {
    expect(configWithEnv('TRAFFIC_BEACON_SCHEDULE_FLUSH', $value)['schedule_flush'])->toBe($expected);
})->with([
    ['true', true],
    ['on', true],
    ['1', true],
    ['false', false],
    ['off', false],
    ['nonsense', false],
]);
