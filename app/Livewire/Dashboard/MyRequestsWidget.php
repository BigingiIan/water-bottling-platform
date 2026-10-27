<?php

namespace App\Livewire\Dashboard;

use App\Support\Dashboard\DashboardWidgetRegistry;
use Illuminate\Contracts\View\View;

class MyRequestsWidget extends WidgetComponent
{
    private const LATEST_REQUESTS = 5;

    public static function key(): string
    {
        return 'my-requests';
    }

    public function render(): View
    {
        $user = $this->authorizeWidget();

        $requestModel = DashboardWidgetRegistry::REQUEST_MODEL;

        return view('livewire.dashboard.my-requests-widget', [
            'requests' => $requestModel::query()->openFor($user)->latest()->limit(self::LATEST_REQUESTS)->get(),
        ]);
    }
}
