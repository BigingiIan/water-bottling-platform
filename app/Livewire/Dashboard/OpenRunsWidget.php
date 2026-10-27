<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;

class OpenRunsWidget extends WidgetComponent
{
    public static function key(): string
    {
        return 'open-runs';
    }

    public function render(): View
    {
        $this->authorizeWidget();

        return view('livewire.dashboard.open-runs-widget', [
            'openRuns' => $this->metrics()->productionToday()['openRuns'],
        ]);
    }
}
