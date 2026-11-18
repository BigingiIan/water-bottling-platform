<?php

namespace App\Livewire;

use App\Models\ForecastRecord;
use App\Models\Promotion;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Calendar\ContextCalendar;
use App\Services\Forecasting\ForecastGenerator;
use App\Services\Forecasting\ForecastOutcome;
use App\Services\Forecasting\ForecastOverview;
use App\Services\MLForecastClient;
use App\Services\SettingsService;
use App\Support\ModuleAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Forecasting', 'breadcrumbSection' => 'Analytics', 'activeItem' => 'Forecasting'])]
class ForecastingPage extends Component
{
    /**
     * How many days ahead the cards show calendar chips for, starting tomorrow.
     */
    private const CHIP_DAYS = 7;

    /**
     * Outcome of the last "Generate forecasts" run, kept for display only.
     *
     * @var array<int, array{sku: string, generated: bool, message: string, modelVersion: ?string}>
     */
    #[Locked]
    public array $generationResults = [];

    public function generateForecasts(ForecastGenerator $generator): void
    {
        $user = $this->user();
        app(ModuleAccess::class)->authorize($user, 'forecasting', write: true);

        $outcomes = $generator->generateTomorrow();
        $this->generationResults = $outcomes
            ->map(fn (ForecastOutcome $outcome): array => [
                'sku' => $outcome->sku,
                'generated' => $outcome->isAvailable(),
                'message' => $this->withoutServiceAddress($outcome->message(), $user),
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

    public function render(ForecastOverview $overview, MLForecastClient $client, SettingsService $settings, ContextCalendar $calendar): View
    {
        $user = $this->user();
        app(ModuleAccess::class)->authorize($user, 'forecasting');

        $cards = $overview->cards();
        $tomorrow = CarbonImmutable::today()->addDay();
        $health = $client->health();

        return view('livewire.forecasting-page', [
            'cards' => $cards,
            'calendarChips' => $this->calendarChips($calendar, $cards->pluck('product.id')->all(), $tomorrow, $tomorrow->addDays(self::CHIP_DAYS - 1)),
            'missingTermYears' => $calendar->yearsWithoutSchoolTerms(CarbonImmutable::today(), CarbonImmutable::today()->addDays(30)),
            'temperatureSources' => DB::table('daily_sku_features')
                ->whereDate('date', $tomorrow->toDateString())
                ->whereNotNull('temperature_source')
                ->distinct()
                ->pluck('temperature_source')
                ->all(),
            'canViewCalendar' => app(ModuleAccess::class)->can($user, 'calendar'),
            'health' => [...$health, 'error' => $health['error'] === null ? null : $this->withoutServiceAddress($health['error'], $user)],
            'serviceUrl' => $this->canSeeServiceAddress($user) ? $client->serviceUrl() : null,
            'describeFailure' => fn (string $message): string => $this->withoutServiceAddress($message, $user),
            'dashboardUrl' => config('services.ml_forecast.dashboard_url'),
            'canGenerate' => app(ModuleAccess::class)->can($user, 'forecasting', write: true),
            'forecastsEnabled' => (bool) $settings->get('forecast.enabled', true),
            'lastRunAt' => ForecastRecord::query()->max('generated_at'),
        ]);
    }

    /**
     * "Promotion planned" and "Public holiday" chips per product for the coming days, from the calendar.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<array{kind: string, label: string, date: CarbonImmutable}>>
     */
    private function calendarChips(ContextCalendar $calendar, array $productIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($productIds === []) {
            return [];
        }

        $context = $calendar->contextBetween($from, $to);
        $chips = array_fill_keys($productIds, []);

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $day = CarbonImmutable::parse($day);
            $holiday = $context['holidays']->first(fn (PublicHoliday $holiday): bool => $holiday->fallsOn($day));

            foreach ($productIds as $productId) {
                if ($context['promotions']->contains(fn (Promotion $promotion): bool => $promotion->appliesTo($productId, $day))) {
                    $chips[$productId][] = ['kind' => 'promotion', 'label' => __('calendar.forecasting.promotion'), 'date' => $day];
                }

                if ($holiday !== null) {
                    $chips[$productId][] = ['kind' => 'holiday', 'label' => __('calendar.forecasting.holiday').': '.$holiday->name, 'date' => $day];
                }
            }
        }

        return $chips;
    }

    /**
     * The ML service's internal address is infrastructure detail, so only administrators see it.
     */
    private function canSeeServiceAddress(User $user): bool
    {
        return $user->role?->slug === 'administrator';
    }

    /**
     * Removes the ML service address from a status or failure message unless the user is an administrator.
     */
    private function withoutServiceAddress(string $message, User $user): string
    {
        if ($this->canSeeServiceAddress($user)) {
            return $message;
        }

        $serviceUrl = app(MLForecastClient::class)->serviceUrl();

        return str_replace([" at {$serviceUrl}", " from {$serviceUrl}", $serviceUrl], ['', ' from the ML service', 'the ML service'], $message);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
