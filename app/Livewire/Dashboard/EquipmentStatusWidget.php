<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;

class EquipmentStatusWidget extends WidgetComponent
{
    public static function key(): string
    {
        return 'equipment-status';
    }

    public function render(): View
    {
        $this->authorizeWidget();

        return view('livewire.dashboard.equipment-status-widget', [
            'equipment' => $this->metrics()->equipmentNotOperational(),
        ]);
    }
}
