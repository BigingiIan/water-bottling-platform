<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    protected $table = 'equipment';

    public const TYPES = ['filler', 'capper', 'labeller', 'conveyor', 'ro_unit', 'uv_steriliser', 'ozone_generator', 'storage_tank', 'compressor', 'generator', 'other'];

    public const STATUSES = ['operational', 'needs_attention', 'under_maintenance', 'out_of_service'];

    /**
     * Statuses that stop or limit the line; scheduling a run on a line with one of these shows a warning.
     */
    public const DISRUPTIVE_STATUSES = ['under_maintenance', 'out_of_service'];

    /**
     * Worst first, used to pick a line's overall status.
     *
     * @var array<string, int>
     */
    public const STATUS_SEVERITY = ['out_of_service' => 3, 'under_maintenance' => 2, 'needs_attention' => 1, 'operational' => 0];

    /**
     * Status changes go through MaintenanceService so they are audited and announced.
     */
    protected $fillable = [
        'code',
        'name',
        'type',
        'production_line_id',
        'location',
        'manufacturer',
        'model',
        'serial_number',
        'installed_on',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'installed_on' => 'date',
        ];
    }

    public function productionLine(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function isOperational(): bool
    {
        return $this->status === 'operational';
    }

    public function isDisruptive(): bool
    {
        return in_array($this->status, self::DISRUPTIVE_STATUSES, true);
    }

    /**
     * Downtime recorded on work orders completed since the given moment.
     */
    public function downtimeMinutesSince(CarbonInterface $since): int
    {
        return (int) $this->workOrders()
            ->where('status', 'done')
            ->where('completed_at', '>=', $since)
            ->sum('downtime_minutes');
    }

    public function statusLabel(): string
    {
        return __('maintenance.equipment_statuses.'.$this->status);
    }

    public static function statusClasses(string $status): string
    {
        return match ($status) {
            'operational' => 'bg-success-soft text-success-ink',
            'needs_attention' => 'bg-warning-soft text-warning-ink',
            'under_maintenance' => 'bg-accent-soft text-accent-ink',
            'out_of_service' => 'bg-danger-soft text-danger-ink',
            default => 'bg-surface-strong text-fg-soft',
        };
    }

    public static function statusDotClasses(string $status): string
    {
        return match ($status) {
            'operational' => 'bg-success',
            'needs_attention' => 'bg-warning',
            'under_maintenance' => 'bg-accent',
            'out_of_service' => 'bg-danger',
            default => 'bg-subtle',
        };
    }
}
