<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\MLForecastClient;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Defer;
use Livewire\Component;
use Throwable;

/**
 * Footer status dot for the ML forecast service. Deferred so the health check
 * never delays the page, and cached so it runs at most once a minute.
 */
#[Defer]
class ForecastServiceStatus extends Component
{
    public const CACHE_KEY = 'footer.ml-forecast-reachable';

    public const CACHE_SECONDS = 60;

    public function placeholder(): string
    {
        return <<<'HTML'
            <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-gray-400">
                <span aria-hidden="true" class="size-2 rounded-full bg-gray-300"></span>
                <span class="sr-only">Checking forecast service</span>
            </span>
            HTML;
    }

    public function render(): View
    {
        $user = auth()->user();
        $isVisible = $user instanceof User && app(ModuleAccess::class)->can($user, 'forecasting');

        return view('livewire.forecast-service-status', [
            'isVisible' => $isVisible,
            'isReachable' => $isVisible && $this->isReachable(),
        ]);
    }

    private function isReachable(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY,
                self::CACHE_SECONDS,
                fn (): bool => app(MLForecastClient::class)->health()['reachable'],
            );
        } catch (Throwable) {
            return false;
        }
    }
}
