<?php

namespace Zzzul\Gol\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Zzzul\Gol\Gol
 */
class Gol extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Zzzul\Gol\Gol::class;
    }
}
