<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;

class OrdersTodayWidget extends WidgetComponent
{
    public static function key(): string
    {
        return 'orders-today';
    }

    public function render(): View
    {
        $this->authorizeWidget();

        return view('livewire.dashboard.orders-today-widget', [
            'orders' => $this->metrics()->ordersToday(),
        ]);
    }
}
