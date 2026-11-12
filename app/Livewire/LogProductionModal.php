<?php

namespace App\Livewire;

use App\Models\ProductionLog;
use App\Models\ProductionSchedule;
use App\Models\User;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class LogProductionModal extends Component
{
    public bool $isOpen = false;

    public ?int $scheduleId = null;

    public string $units = '';

    public string $qualityStatus = 'passed';

    public string $notes = '';

    #[On('open-log-modal')]
    public function openModal(?int $scheduleId = null): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production');

        if ($scheduleId !== null) {
            ProductionSchedule::query()->with('productionLine')->findOrFail($scheduleId);
        }

        $this->reset(['units', 'qualityStatus', 'notes']);
        $this->qualityStatus = 'passed';
        $this->scheduleId = $scheduleId;
        $this->isOpen = true;
    }

    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->reset(['scheduleId', 'units', 'qualityStatus', 'notes']);
        $this->qualityStatus = 'passed';
    }

    public function saveLog(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production', write: true);

        $validated = $this->validate([
            'scheduleId' => ['required', 'integer', Rule::exists('production_schedules', 'id')],
            'units' => ['required', 'integer', 'min:1', 'max:1000000'],
            'qualityStatus' => ['required', Rule::in(['passed', 'flagged'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // The run is locked while the entry is written so it cannot be completed underneath it:
        // a log added after completion would leave the batch's volume, loss % and leakage report stale.
        $scheduleStatus = DB::transaction(function () use ($validated, $user): ?string {
            $schedule = ProductionSchedule::query()->lockForUpdate()->find($validated['scheduleId']);

            if (! $schedule instanceof ProductionSchedule || $schedule->status !== 'active') {
                return $schedule?->status ?? 'missing';
            }

            ProductionLog::create([
                'production_schedule_id' => $schedule->id,
                'operator_id' => $user->id,
                'units_logged' => (int) $validated['units'],
                'logged_at' => now(),
                'quality_status' => $validated['qualityStatus'],
                'notes' => $validated['notes'] ?: null,
            ]);

            return null;
        });

        if ($scheduleStatus !== null) {
            $this->addError('scheduleId', match ($scheduleStatus) {
                'queued' => 'This run has not been started yet. Start the run before logging production.',
                'complete' => 'This run is already complete, so no more production can be logged against it.',
                default => 'This run is no longer available.',
            });

            return;
        }

        $this->dispatch('production-logged', scheduleId: $validated['scheduleId']);
        $this->closeModal();
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production');

        $schedule = $this->scheduleId !== null
            ? ProductionSchedule::query()->with('productionLine')->find($this->scheduleId)
            : null;

        return view('livewire.log-production-modal', [
            'schedule' => $schedule,
            'openSchedules' => ProductionSchedule::query()
                ->with('productionLine')
                ->whereDate('scheduled_date', today()->toDateString())
                ->where('status', 'active')
                ->orderBy('scheduled_time')
                ->get(),
        ]);
    }
}
