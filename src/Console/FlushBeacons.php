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
        $result = $buffer->flush();

        if ($result->dropped() > 0) {
            $this->components->error("Sent {$result->sent} beacons, dropped {$result->dropped()}. Check the log for why.");

            return self::FAILURE;
        }

        $this->components->info("Sent {$result->sent} beacons.");

        return self::SUCCESS;
    }
}
