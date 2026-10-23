<?php

namespace App\Console\Commands;

use App\Models\ErrorEvent;
use App\Services\SettingsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('errors:prune')]
#[Description('Delete error events past the configured retention period')]
class PruneErrorEvents extends Command
{
    public function handle(SettingsService $settings): int
    {
        $retentionDays = $settings->get('retention.error_log_days', 30);
        $deletedEvents = ErrorEvent::query()
            ->where('created_at', '<', Carbon::now()->subDays($retentionDays))
            ->delete();

        $this->info("Pruned {$deletedEvents} error events.");

        return self::SUCCESS;
    }
}
