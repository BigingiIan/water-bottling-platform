<?php

namespace App\Models;

use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maintenance work on one piece of equipment. Status, timings and completion fields change only through
 * MaintenanceService.
 */
class WorkOrder extends Model
{
    /** @use HasFactory<WorkOrderFactory> */
    use HasFactory;

    public const TYPES = ['preventive', 'corrective', 'inspection'];

    public const SOURCES = ['plan', 'request', 'breakdown'];

    public const PRIORITIES = ['low', 'normal', 'urgent'];

    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    public const ACTIVE_STATUSES = ['open', 'in_progress'];

    protected $fillable = [
        'reference',
        'equipment_id',
        'maintenance_plan_id',
        'due_on',
        'request_id',
        'type',
        'source',
        'priority',
        'reported_by',
        'assigned_to',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'downtime_minutes' => 'integer',
            'cost_kes' => 'decimal:2',
            'checklist_results' => 'array',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DepartmentRequest::class, 'request_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public static function statusClasses(string $status): string
    {
        return match ($status) {
            'open' => 'bg-accent-soft text-accent-ink',
            'in_progress' => 'bg-warning-soft text-warning-ink',
            'done' => 'bg-success-soft text-success-ink',
            default => 'bg-surface-strong text-fg-soft',
        };
    }
}
