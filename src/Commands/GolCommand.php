<?php

namespace Zzzul\Gol\Commands;

use Illuminate\Console\Command;

class GolCommand extends Command
{
    public $signature = 'gol';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
