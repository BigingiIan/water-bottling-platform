<?php

namespace App\Console\Commands;

use App\Models\ErrorEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('errors:prune')]
#[Description('Delete error events older than 30 days')]
class PruneErrorEvents extends Command
{
    public function handle(): int
    {
        $deletedEvents = ErrorEvent::query()
            ->where('created_at', '<', Carbon::now()->subDays(30))
            ->delete();

        $this->info("Pruned {$deletedEvents} error events.");

        return self::SUCCESS;
    }
}
