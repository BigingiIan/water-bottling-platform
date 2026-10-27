<?php

namespace App\Livewire\Dashboard;

use App\Support\Dashboard\DashboardWidgetRegistry;
use Illuminate\Contracts\View\View;

class TodaysOutputWidget extends WidgetComponent
{
    public static function key(): string
    {
        return 'todays-output';
    }

    public function render(): View
    {
        $user = $this->authorizeWidget();

        return view('livewire.dashboard.todays-output-widget', [
            'production' => $this->metrics()->productionToday(),
            'canCreateSchedule' => app(DashboardWidgetRegistry::class)->canAccessModule($user, 'production', 'full'),
        ]);
    }
}
