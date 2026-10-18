<?php

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function redisAvailable(): bool
{
    $socket = @fsockopen((string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), $errno, $error, 0.2);

    if ($socket === false) {
        return false;
    }

    fclose($socket);

    return true;
}
