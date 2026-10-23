<?php

namespace App\Livewire;

use App\Models\ForecastRecord;
use App\Models\User;
use App\Services\Forecasting\ForecastGenerator;
use App\Services\Forecasting\ForecastOutcome;
use App\Services\Forecasting\ForecastOverview;
use App\Services\MLForecastClient;
use App\Services\SettingsService;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Forecasting', 'breadcrumbSection' => 'Analytics', 'activeItem' => 'Forecasting'])]
class ForecastingPage extends Component
{
    /**
     * Outcome of the last "Generate forecasts" run, kept for display only.
     *
     * @var array<int, array{sku: string, generated: bool, message: string, modelVersion: ?string}>
     */
    #[Locked]
    public array $generationResults = [];

    public function generateForecasts(ForecastGenerator $generator): void
    {
        app(ModuleAccess::class)->authorize($this->user(), 'forecasting', write: true);

        $outcomes = $generator->generateTomorrow();
        $this->generationResults = $outcomes
            ->map(fn (ForecastOutcome $outcome): array => [
                'sku' => $outcome->sku,
                'generated' => $outcome->isAvailable(),
                'message' => $outcome->message(),
                'modelVersion' => $outcome->modelVersion,
            ])
            ->values()
            ->all();

        $generated = $outcomes->filter(fn (ForecastOutcome $outcome): bool => $outcome->isAvailable())->count();
        $this->dispatch('forecasts-generated', message: "Generated {$generated} of {$outcomes->count()} forecasts.");
    }

    public function dismissResults(): void
    {
        $this->generationResults = [];
    }

    public function render(ForecastOverview $overview, MLForecastClient $client, SettingsService $settings): View
    {
        $user = $this->user();
        app(ModuleAccess::class)->authorize($user, 'forecasting');

        return view('livewire.forecasting-page', [
            'cards' => $overview->cards(),
            'health' => $client->health(),
            'serviceUrl' => $client->serviceUrl(),
            'dashboardUrl' => config('services.ml_forecast.dashboard_url'),
            'canGenerate' => app(ModuleAccess::class)->can($user, 'forecasting', write: true),
            'forecastsEnabled' => (bool) $settings->get('forecast.enabled', true),
            'lastRunAt' => ForecastRecord::query()->max('generated_at'),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
