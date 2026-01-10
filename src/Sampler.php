<?php

namespace LuangDev\Serap;

final class Sampler
{
    public function __construct(
        private readonly float $rate = 0.1 // 10% default
    ) {}

    public function shouldSample(string $traceId): bool
    {
        // rate boundaries
        if ($this->rate <= 0) {
            return false;
        }
        if ($this->rate >= 1) {
            return true;
        }

        // Deterministic sampling:
        // hash traceId -> uint32 -> [0..1)
        $hashHex = substr(hash('sha256', $traceId), 0, 8);
        $u32 = hexdec($hashHex); // 0..2^32-1
        $ratio = $u32 / 4294967296; // 2^32

        return $ratio < $this->rate;
    }
}
