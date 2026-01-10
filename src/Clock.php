<?php

namespace LuangDev\Serap;

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
