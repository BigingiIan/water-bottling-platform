<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;

class MaintenanceDueWidget extends WidgetComponent
{
    public static function key(): string
    {
        return 'maintenance-due';
    }

    public function render(): View
    {
        $this->authorizeWidget();

        return view('livewire.dashboard.maintenance-due-widget', [
            'due' => $this->metrics()->maintenanceDue(),
        ]);
    }
}
