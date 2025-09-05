<?php

namespace LuangDev\Serap\Commands;

use Illuminate\Console\Command;

class SerapCommand extends Command
{
    public $signature = 'serap';

    public $description = 'Serap Command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
