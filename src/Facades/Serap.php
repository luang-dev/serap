<?php

namespace LuangDev\Serap\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \LuangDev\Serap\Serap
 */
class Serap extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LuangDev\Serap\Serap::class;
    }
}
