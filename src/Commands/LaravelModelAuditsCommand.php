<?php

namespace SoftArtisan\LaravelModelAudits\Commands;

use Illuminate\Console\Command;

class LaravelModelAuditsCommand extends Command
{
    public $signature = 'laravel-model-audits';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
