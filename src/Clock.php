<?php

namespace LuangDev\Serap;

use DateTimeImmutable;
use DateTimeZone;

final class Clock
{
    public function monotonic(): int
    {
        return hrtime(true);
    }

    public function unix(): float
    {
        return microtime(true);
    }
}
