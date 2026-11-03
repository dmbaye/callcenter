<?php

namespace App\Console\Commands;

use App\Services\CallRecordings;
use Illuminate\Console\Command;

class PruneCallRecordings extends Command
{
    protected $signature = 'recordings:prune';

    protected $description = 'Delete call recordings older than each team\'s plan retention';

    public function handle(CallRecordings $recordings): int
    {
        $this->info($recordings->prune().' recording(s) deleted.');

        return self::SUCCESS;
    }
}
