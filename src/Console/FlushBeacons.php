<?php

namespace Bitpixel\TrafficBeacon\Console;

use Bitpixel\TrafficBeacon\BeaconBuffer;
use Illuminate\Console\Command;

class FlushBeacons extends Command
{
    protected $signature = 'traffic-beacon:flush';

    protected $description = 'Send buffered traffic beacons to the dashboard';

    public function handle(BeaconBuffer $buffer): int
    {
        $this->components->info("Sent {$buffer->flush()} beacons.");

        return self::SUCCESS;
    }
}
