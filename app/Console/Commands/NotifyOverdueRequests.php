<?php

namespace App\Console\Commands;

use App\Models\DepartmentRequest;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('requests:notify-overdue')]
#[Description('Notify departments about requests past their due date (once per request while unread)')]
class NotifyOverdueRequests extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $overdue = DepartmentRequest::query()->overdue()->orderBy('due_date')->orderBy('id')->get();

        foreach ($overdue as $request) {
            $notifications->requestOverdue($request);
        }

        $this->info("Checked {$overdue->count()} overdue ".str('request')->plural($overdue->count()).'.');

        return self::SUCCESS;
    }
}
