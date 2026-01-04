<?php

namespace LuangDev\Serap;

use DateTimeImmutable;
use DateTimeZone;

final class Clock
{
    public function nowIso8601(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    public function monotonicNs(): int
    {
        return hrtime(true);
    }

    public function microtime(): float
    {
        return microtime(true);
    }
}
