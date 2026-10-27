<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;

class LeakageReviewWidget extends WidgetComponent
{
    public static function key(): string
    {
        return 'leakage-review';
    }

    public function render(): View
    {
        $this->authorizeWidget();

        return view('livewire.dashboard.leakage-review-widget', [
            'leakageAttentionCount' => $this->metrics()->leakageAttentionCount(),
        ]);
    }
}
