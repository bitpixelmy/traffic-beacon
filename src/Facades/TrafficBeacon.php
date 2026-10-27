<?php

namespace Bitpixel\TrafficBeacon\Facades;

use Bitpixel\TrafficBeacon\IssueReporter;
use Illuminate\Support\Facades\Facade;

/**
 * Report an exception the app caught and handled itself to the dashboard's
 * Issues tab: `TrafficBeacon::report($exception)`.
 *
 * @method static void report(\Throwable $exception)
 * @method static int flush()
 *
 * @see IssueReporter
 */
class TrafficBeacon extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IssueReporter::class;
    }
}
