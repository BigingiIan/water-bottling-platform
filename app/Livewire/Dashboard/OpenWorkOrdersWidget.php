<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;

class OpenWorkOrdersWidget extends WidgetComponent
{
    public static function key(): string
    {
        return 'open-work-orders';
    }

    public function render(): View
    {
        $this->authorizeWidget();

        return view('livewire.dashboard.open-work-orders-widget', [
            'workOrders' => $this->metrics()->openWorkOrders(),
        ]);
    }
}
