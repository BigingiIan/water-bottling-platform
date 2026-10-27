<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;

class StockAlertsWidget extends WidgetComponent
{
    public static function key(): string
    {
        return 'stock-alerts';
    }

    public function render(): View
    {
        $this->authorizeWidget();

        return view('livewire.dashboard.stock-alerts-widget', [
            'stockAlerts' => $this->metrics()->stockAlerts(),
        ]);
    }
}
