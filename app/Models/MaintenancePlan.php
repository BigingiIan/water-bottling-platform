<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\MaintenancePlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A preventive task repeated every N days. next_due_on is stored so due tasks can be queried, and is
 * always recomputed by nextDueFor() when the plan or its last completion changes.
 */
class MaintenancePlan extends Model
{
    /** @use HasFactory<MaintenancePlanFactory> */
    use HasFactory;

    protected $fillable = [
        'equipment_id',
        'task',
        'every_days',
        'first_due_on',
        'last_done_on',
        'next_due_on',
        'assigned_role_id',
        'checklist',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'every_days' => 'integer',
            'first_due_on' => 'date',
            'last_done_on' => 'date',
            'next_due_on' => 'date',
            'checklist' => 'array',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MaintenancePlan $plan): void {
            $plan->next_due_on = self::nextDueFor($plan->first_due_on, $plan->last_done_on, (int) $plan->every_days)->toDateString();
        });
    }

    /**
     * Last done plus the interval, or the first due date while the task has never been done.
     */
    public static function nextDueFor(CarbonInterface $firstDueOn, ?CarbonInterface $lastDoneOn, int $everyDays): CarbonImmutable
    {
        return $lastDoneOn === null
            ? CarbonImmutable::parse($firstDueOn->toDateString())
            : CarbonImmutable::parse($lastDoneOn->toDateString())->addDays($everyDays);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function assignedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'assigned_role_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function isOverdue(?CarbonInterface $today = null): bool
    {
        return $this->active && $this->next_due_on->lessThan(($today ?? today())->copy()->startOfDay());
    }

    /**
     * @return list<string>
     */
    public function checklistSteps(): array
    {
        return array_values(array_filter(array_map('strval', (array) $this->checklist), fn (string $step): bool => trim($step) !== ''));
    }
}
