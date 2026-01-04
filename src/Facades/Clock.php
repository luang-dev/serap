<?php

namespace LuangDev\Serap\Facades;

use Illuminate\Support\Facades\Facade;

final class Clock extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LuangDev\Serap\Clock::class;
    }
}
