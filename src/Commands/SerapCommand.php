<?php

namespace LuangDev\Serap\Commands;

use Illuminate\Console\Command;

class SerapCommand extends Command
{
    public $signature = 'gol';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
