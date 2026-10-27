<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;

class RecentActivityWidget extends WidgetComponent
{
    private const LATEST_ENTRIES = 6;

    public static function key(): string
    {
        return 'recent-activity';
    }

    public function render(): View
    {
        $this->authorizeWidget();

        return view('livewire.dashboard.recent-activity-widget', [
            'entries' => $this->metrics()->recentActivity(self::LATEST_ENTRIES),
        ]);
    }
}
