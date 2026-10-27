<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;

class ReceivablesWidget extends WidgetComponent
{
    public static function key(): string
    {
        return 'receivables';
    }

    public function render(): View
    {
        $this->authorizeWidget();

        return view('livewire.dashboard.receivables-widget', [
            'receivables' => $this->metrics()->receivables(),
        ]);
    }
}
