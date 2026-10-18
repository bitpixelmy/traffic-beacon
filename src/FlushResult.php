<?php

namespace Bitpixel\TrafficBeacon;

/**
 * What a flush did: how many beacons it took from the buffer and how many the
 * dashboard accepted. The rest were dropped.
 */
final readonly class FlushResult
{
    public function __construct(
        public int $taken,
        public int $sent,
    ) {}

    public function dropped(): int
    {
        return $this->taken - $this->sent;
    }
}
