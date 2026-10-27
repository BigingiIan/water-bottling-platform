<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;

class ForecastCoverWidget extends WidgetComponent
{
    public static function key(): string
    {
        return 'forecast-cover';
    }

    public function render(): View
    {
        $this->authorizeWidget();

        return view('livewire.dashboard.forecast-cover-widget', [
            'forecastCover' => $this->metrics()->forecastCover(),
        ]);
    }
}
