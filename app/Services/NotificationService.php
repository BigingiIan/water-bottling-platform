<?php

namespace App\Services;

use App\Models\ErrorEvent;
use App\Models\LeakageReport;
use App\Models\Product;
use App\Models\ProductionSchedule;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\LeakageReportFlag;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    private const STOCK_STATUS_RANK = [
        'ok' => 0,
        'low' => 1,
        'critical' => 2,
    ];

    private const PRODUCTION_TARGET_RATIO = 0.9;

    public function __construct(
        private SettingsService $settings,
        private UserPreferenceService $preferences,
    ) {}

    public function stockStatusChanged(Product $product, string $oldStatus, string $newStatus, ?User $actor): void
    {
        $this->guard('stock', function () use ($product, $oldStatus, $newStatus, $actor): void {
            $isWorsening = (self::STOCK_STATUS_RANK[$newStatus] ?? 0) > (self::STOCK_STATUS_RANK[$oldStatus] ?? 0);

            if (! $isWorsening || ! $this->settings->get("notify.{$newStatus}_stock", true)) {
                return;
            }

            $this->send($this->usersWithModuleAccess(['inventory'], ['view', 'full']), [
                'type' => "stock.{$newStatus}",
                'title' => "{$product->name} is ".($newStatus === 'critical' ? 'critically low' : 'running low'),
                'body' => "Stock dropped below the {$newStatus} threshold (reorder at ".number_format((float) $product->reorder_threshold).').',
                'url' => route('inventory.overview', absolute: false),
                'severity' => $newStatus === 'critical' ? 'critical' : 'warning',
                'key' => "stock:{$product->id}:{$newStatus}",
            ], $actor, "notify.{$newStatus}_stock");
        });
    }

    public function leakageReportFlagged(LeakageReport $report, ?User $actor): void
    {
        $this->guard('leakage', function () use ($report, $actor): void {
            $flag = LeakageReportFlag::fromVolumes((float) $report->expected_volume, (float) $report->actual_volume);

            if (! in_array($flag, ['Review', 'Investigate'], true) || ! $this->settings->get('notify.leakage_flag', true)) {
                return;
            }

            $batchReference = 'B-'.str_pad((string) $report->batch_id, 5, '0', STR_PAD_LEFT);

            $this->send($this->usersWithModuleAccess(['inventory', 'production'], ['view', 'full']), [
                'type' => 'leakage.flagged',
                'title' => "Leakage flagged for {$flag} on {$batchReference}",
                'body' => 'Discrepancy of '.number_format((float) $report->discrepancy, 2).' L against '.number_format((float) $report->expected_volume, 2).' L expected.',
                'url' => route('inventory.overview', ['tab' => 'leakage'], absolute: false),
                'severity' => $flag === 'Investigate' ? 'critical' : 'warning',
                'key' => "leakage:{$report->id}",
            ], $actor, 'notify.leakage_flag');
        });
    }

    public function errorGroupDetected(ErrorEvent $event): void
    {
        $this->guard('error', function () use ($event): void {
            if (! $this->settings->get('notify.system_errors', true)) {
                return;
            }

            $administrators = User::query()
                ->where('status', '!=', 'suspended')
                ->whereHas('role', fn (Builder $query): Builder => $query->where('slug', 'administrator'))
                ->get();

            $this->send($administrators, [
                'type' => 'error.new_group',
                'title' => 'New server error: '.class_basename((string) $event->exception_class),
                'body' => "{$event->method} {$event->path} · reference {$event->reference}",
                'url' => route('errors.index', absolute: false),
                'severity' => 'critical',
                'key' => "error:{$event->fingerprint}",
            ], $event->user_id === null ? null : User::query()->find($event->user_id), 'notify.system_errors');
        });
    }

    public function productionRunCompleted(ProductionSchedule $run, ?User $actor): void
    {
        $this->guard('production', function () use ($run, $actor): void {
            $targetUnits = (int) $run->target_units;
            $actualUnits = (int) $run->logs()->sum('units_logged');

            if ($targetUnits <= 0 || $actualUnits >= $targetUnits * self::PRODUCTION_TARGET_RATIO) {
                return;
            }

            $achievedPercent = round($actualUnits / $targetUnits * 100, 1);

            $this->send($this->usersWithModuleAccess(['production'], ['full']), [
                'type' => 'production.below_target',
                'title' => "{$run->product_sku} run completed at {$achievedPercent}% of target",
                'body' => number_format($actualUnits).' of '.number_format($targetUnits).' units produced.',
                'url' => route('production.history', absolute: false),
                'severity' => 'warning',
                'key' => "production:{$run->id}:below_target",
            ], $actor);
        });
    }

    /**
     * @param  array<string>  $moduleSlugs
     * @param  array<string>  $accessLevels
     * @return Collection<int, User>
     */
    public function usersWithModuleAccess(array $moduleSlugs, array $accessLevels): Collection
    {
        $roleIds = DB::table('role_module_permissions')
            ->join('modules', 'modules.id', '=', 'role_module_permissions.module_id')
            ->whereIn('modules.slug', $moduleSlugs)
            ->whereIn('role_module_permissions.access_level', $accessLevels)
            ->select('role_module_permissions.role_id');

        return User::query()
            ->where('status', '!=', 'suspended')
            ->whereIn('role_id', $roleIds)
            ->get();
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @param  array{type: string, title: string, body: string, url: string, severity: 'info'|'warning'|'critical', key: string}  $payload
     */
    private function send(Collection $recipients, array $payload, ?User $actor, ?string $preferenceKey = null): void
    {
        $recipients = $recipients->reject(fn (User $recipient): bool => $actor !== null && $recipient->is($actor));

        if ($preferenceKey !== null) {
            $optedOutIds = $this->preferences->userIdsOptedOutOf($recipients->modelKeys(), $preferenceKey);
            $recipients = $recipients->reject(fn (User $recipient): bool => in_array($recipient->id, $optedOutIds, true));
        }

        if ($recipients->isEmpty()) {
            return;
        }

        $alreadyNotifiedIds = DB::table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $recipients->modelKeys())
            ->whereNull('read_at')
            ->where('data->key', $payload['key'])
            ->pluck('notifiable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($recipients as $recipient) {
            if (! in_array($recipient->id, $alreadyNotifiedIds, true)) {
                $recipient->notify(new SystemNotification($payload));
            }
        }
    }

    private function guard(string $producer, Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::error('Failed to create notification.', [
                'producer' => $producer,
                'exception' => $exception,
            ]);
        }
    }
}
