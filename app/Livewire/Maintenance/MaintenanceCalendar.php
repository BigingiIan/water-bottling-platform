<?php

namespace App\Livewire\Maintenance;

use App\Models\MaintenancePlan;
use App\Models\User;
use App\Support\ModuleAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Maintenance Calendar', 'breadcrumbSection' => 'Maintenance', 'activeItem' => 'Maintenance Calendar'])]
class MaintenanceCalendar extends Component
{
    public const WEEKS_AHEAD = 8;

    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'maintenance');

        $today = CarbonImmutable::today();
        $end = $today->addWeeks(self::WEEKS_AHEAD);
        $plans = MaintenancePlan::query()
            ->with(['equipment:id,code,name,status', 'assignedRole:id,name'])
            ->where('active', true)
            ->whereDate('next_due_on', '<=', $end->toDateString())
            ->orderBy('next_due_on')
            ->get();

        return view('livewire.maintenance.maintenance-calendar', [
            'overdue' => $plans->filter(fn (MaintenancePlan $plan): bool => $plan->next_due_on->lessThan($today))->values(),
            'weeks' => $this->weeks($plans, $today, $end),
        ]);
    }

    /**
     * Upcoming occurrences grouped by week (Monday first). Only the next due date is real; later repeats
     * are projected from the interval and move when the task is done early or late.
     *
     * @return array<string, list<array{plan: MaintenancePlan, date: CarbonImmutable, projected: bool}>>
     */
    private function weeks($plans, CarbonImmutable $today, CarbonImmutable $end): array
    {
        $occurrences = [];

        foreach ($plans as $plan) {
            $date = CarbonImmutable::parse($plan->next_due_on->toDateString());
            $projected = false;

            while ($date->lessThanOrEqualTo($end)) {
                if ($date->greaterThanOrEqualTo($today)) {
                    $occurrences[] = ['plan' => $plan, 'date' => $date, 'projected' => $projected];
                }

                $date = $date->addDays(max(1, $plan->every_days));
                $projected = true;
            }
        }

        usort($occurrences, fn (array $a, array $b): int => [$a['date']->getTimestamp(), $a['plan']->id] <=> [$b['date']->getTimestamp(), $b['plan']->id]);
        $weeks = [];

        foreach ($occurrences as $occurrence) {
            $weeks[$occurrence['date']->startOfWeek(CarbonImmutable::MONDAY)->toDateString()][] = $occurrence;
        }

        return $weeks;
    }
}
