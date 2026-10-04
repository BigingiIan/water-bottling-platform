<?php

namespace App\Livewire;

use App\Models\Product;
use App\Models\ProductionLine;
use App\Models\ProductionSchedule;
use App\Models\User;
use App\Services\MLForecastClient;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
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

    #[On('open-create-modal')]
    public function openModal(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production', write: true);

        $this->reset(['scheduledDate', 'scheduledTime', 'productSku', 'productionLineId', 'targetUnits']);
        $this->scheduledDate = today()->toDateString();
        $this->isOpen = true;
    }

    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->reset(['scheduledDate', 'scheduledTime', 'productSku', 'productionLineId', 'targetUnits']);
    }

    public function applyForecast(): void
    {
        $suggestion = $this->forecastSuggestion();

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
        ]);
    }

    /**
     * ML demand suggestion for the currently selected SKU and date.
     *
     * The client returns only a nullable float, so an unavailable forecast
     * carries no reason — the blade shows the generic unavailable message
     * and creation stays allowed.
     *
     * @return null|array{available:true,demand:int}|array{available:false}
     */
    private function forecastSuggestion(): ?array
    {
        if ($this->productSku === '' || $this->scheduledDate === '') {
            return null;
        }

        try {
            $prediction = app(MLForecastClient::class)->predict($this->productSku, $this->scheduledDate);
        } catch (\Throwable) {
            return ['available' => false];
        }

        if ($prediction === null) {
            return ['available' => false];
        }

        return ['available' => true, 'demand' => (int) round($prediction)];
    }
}
