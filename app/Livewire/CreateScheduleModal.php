<?php

namespace App\Livewire;

use App\Models\ForecastRecord;
use App\Models\Product;
use App\Models\ProductionLine;
use App\Models\ProductionSchedule;
use App\Models\User;
use App\Services\Maintenance\LineStatus;
use App\Services\MLForecastClient;
use App\Services\SettingsService;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class CreateScheduleModal extends Component
{
    public bool $isOpen = false;

    public string $scheduledDate = '';

    public string $scheduledTime = '';

    public string $productSku = '';

    public ?int $productionLineId = null;

    public string $targetUnits = '';

    /**
     * The "SKU|date" the ML service was last asked about without getting a forecast back.
     */
    #[Locked]
    public string $forecastUnavailableFor = '';

    #[On('open-create-modal')]
    public function openModal(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production', write: true);

        $this->reset(['scheduledDate', 'scheduledTime', 'productSku', 'productionLineId', 'targetUnits', 'forecastUnavailableFor']);
        $this->scheduledDate = today()->toDateString();
        $this->isOpen = true;
    }

    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->reset(['scheduledDate', 'scheduledTime', 'productSku', 'productionLineId', 'targetUnits', 'forecastUnavailableFor']);
    }

    /**
     * Asks the ML service for a fresh forecast for the selected SKU and date and stores it.
     *
     * This is the only place the modal calls the service: rendering reads the stored record,
     * so typing in the form never waits on a slow or unreachable service.
     */
    public function requestForecast(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production', write: true);

        if (! $this->forecastIsRequestable()) {
            return;
        }

        try {
            $prediction = app(MLForecastClient::class)->predict($this->productSku, $this->scheduledDate);
        } catch (\Throwable) {
            $prediction = null;
        }

        $this->forecastUnavailableFor = $prediction === null ? $this->forecastKey() : '';
    }

    public function applyForecast(): void
    {
        $suggestion = $this->forecastSuggestion();

        if ($suggestion !== null && ! $suggestion['available'] && ! $suggestion['requested']) {
            $this->requestForecast();
            $suggestion = $this->forecastSuggestion();
        }

        if (($suggestion['available'] ?? false) && isset($suggestion['demand'])) {
            $this->targetUnits = (string) $suggestion['demand'];
        }
    }

    public function saveSchedule(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production', write: true);

        if (preg_match('/^\d{2}:\d{2}$/', $this->scheduledTime) === 1) {
            $this->scheduledTime .= ':00';
        }

        $validated = $this->validate([
            'scheduledDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'scheduledTime' => ['required', 'date_format:H:i:s'],
            'productSku' => ['required', 'string', 'max:255', Rule::exists('products', 'unit_type')],
            'productionLineId' => [
                'required',
                'integer',
                Rule::exists('production_lines', 'id'),
                Rule::unique('production_schedules', 'production_line_id')->where(function ($query): void {
                    $query->whereDate('scheduled_date', $this->scheduledDate)
                        ->where('scheduled_time', $this->scheduledTime);
                }),
            ],
            'targetUnits' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $schedule = ProductionSchedule::create([
            'product_sku' => $validated['productSku'],
            'production_line_id' => $validated['productionLineId'],
            'scheduled_date' => $validated['scheduledDate'],
            'scheduled_time' => $validated['scheduledTime'],
            'target_units' => (int) $validated['targetUnits'],
            'status' => 'queued',
        ]);

        $this->dispatch('schedule-created', scheduleId: $schedule->id);
        $this->closeModal();
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production');

        return view('livewire.create-schedule-modal', [
            'lines' => ProductionLine::query()->orderBy('name')->get(),
            'skus' => Product::query()->distinct()->orderBy('unit_type')->pluck('unit_type'),
            'forecastSuggestion' => $this->forecastSuggestion(),
            // A warning, not a block: production decides, knowing the line has equipment down.
            'lineWarning' => $this->isOpen ? app(LineStatus::class)->warningFor($this->productionLineId) : null,
        ]);
    }

    /**
     * ML demand suggestion for the currently selected SKU and date, read from the stored forecast.
     *
     * Never calls the ML service: with nothing stored the blade offers to fetch one
     * (requested false), and after a fetch that returned nothing it shows the generic
     * unavailable message (requested true). Creation stays allowed either way.
     *
     * @return null|array{available:true,demand:int}|array{available:false,requested:bool}
     */
    private function forecastSuggestion(): ?array
    {
        if (! $this->forecastIsRequestable()) {
            return null;
        }

        $demand = ForecastRecord::query()
            ->whereIn('product_id', Product::query()->where('unit_type', $this->productSku)->select('id'))
            ->whereDate('forecast_date', $this->scheduledDate)
            ->where('horizon_days', 1)
            ->latestGenerated()
            ->value('predicted_demand');

        if ($demand === null) {
            return ['available' => false, 'requested' => $this->forecastUnavailableFor === $this->forecastKey()];
        }

        return ['available' => true, 'demand' => (int) round((float) $demand)];
    }

    /**
     * Whether forecasting is on and a SKU and a well-formed date are selected.
     */
    private function forecastIsRequestable(): bool
    {
        return app(SettingsService::class)->get('forecast.enabled', true)
            && $this->productSku !== ''
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->scheduledDate) === 1;
    }

    private function forecastKey(): string
    {
        return $this->productSku.'|'.$this->scheduledDate;
    }
}
