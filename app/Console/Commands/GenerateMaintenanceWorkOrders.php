<?php

namespace App\Console\Commands;

use App\Services\Maintenance\MaintenanceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('maintenance:generate-work-orders')]
#[Description('Create work orders for preventive plans due within two days (once per due date) and remind about overdue plans')]
class GenerateMaintenanceWorkOrders extends Command
{
    public function handle(MaintenanceService $maintenance): int
    {
        $result = $maintenance->generateDueWorkOrders();
        $count = count($result['created']);

        $this->info("Created {$count} work ".str('order')->plural($count).($count > 0 ? ': '.implode(', ', $result['created']) : '').'.');

        if ($result['overdue'] > 0) {
            $this->warn("{$result['overdue']} preventive ".str('plan')->plural($result['overdue']).' overdue.');
        }

        return self::SUCCESS;
    }
}
