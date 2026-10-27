<?php

namespace App\Services;

use App\Models\DepartmentRequest;
use App\Models\ErrorEvent;
use App\Models\Invoice;
use App\Models\LeakageReport;
use App\Models\Module;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionSchedule;
use App\Models\RequestComment;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Leakage\LeakageDetector;
use App\Services\Orders\Money;
use App\Services\Requests\RequestPriority;
use App\Services\Requests\RequestStatus;
use App\Services\Requests\RequestType;
use App\Support\LeakageReportFlag;
use App\Support\Plural;
use App\Support\SettingsDate;
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
        'out' => 3,
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
            // Out of stock is reported through the critical-stock setting and preference.
            $notificationKey = $newStatus === 'low' ? 'notify.low_stock' : 'notify.critical_stock';

            if (! $isWorsening || ! $this->settings->get($notificationKey, true)) {
                return;
            }

            $this->send($this->usersWithModuleAccess(['inventory'], ['view', 'full']), [
                'type' => "stock.{$newStatus}",
                'title' => "{$product->name} is ".match ($newStatus) {
                    'out' => 'out of stock',
                    'critical' => 'critically low',
                    default => 'running low',
                },
                'body' => $newStatus === 'out'
                    ? 'No units remain across all warehouse lines.'
                    : "Stock dropped below the {$newStatus} threshold (reorder at ".number_format((float) $product->reorder_threshold).').',
                'url' => route('inventory.overview', absolute: false),
                'severity' => $newStatus === 'low' ? 'warning' : 'critical',
                'key' => "stock:{$product->id}:{$newStatus}",
            ], $actor, $notificationKey);
        });
    }

    public function leakageReportFlagged(LeakageReport $report, ?User $actor): void
    {
        $this->guard('leakage', function () use ($report, $actor): void {
            $severity = $report->severity
                ?? app(LeakageDetector::class)->gradeManual((string) $report->expected_volume, (string) $report->actual_volume)['severity'];
            $flag = LeakageReportFlag::fromSeverity($severity);

            if ($severity === null || ! $this->settings->get('notify.leakage_flag', true)) {
                return;
            }

            $subject = $report->batch_id !== null
                ? 'B-'.str_pad((string) $report->batch_id, 5, '0', STR_PAD_LEFT)
                : trim(($report->product?->unit_type ?? 'stock').' '.($report->warehouse_location ? "at {$report->warehouse_location}" : ''));
            $what = match ($report->finding) {
                'process_loss' => 'Process loss',
                'logging_gap' => 'Logging gap',
                'stock_shrinkage' => 'Stock shrinkage',
                default => 'Leakage',
            };

            $this->send($this->usersWithModuleAccess(['inventory', 'production'], ['view', 'full']), [
                'type' => 'leakage.flagged',
                'title' => "{$what} flagged for {$flag} on {$subject}".($report->detected_by === 'anomaly' ? ' (unusual)' : ''),
                'body' => $report->explanation
                    ?? 'Discrepancy of '.number_format((float) $report->discrepancy, 2).' L against '.number_format((float) $report->expected_volume, 2).' L expected.',
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

    public function accessRequested(User $requester, Module $module): void
    {
        $this->guard('access', function () use ($requester, $module): void {
            $administrators = User::query()
                ->where('status', '!=', 'suspended')
                ->whereHas('role', fn (Builder $query): Builder => $query->where('slug', 'administrator'))
                ->get();

            $this->send($administrators, [
                'type' => 'access.requested',
                'title' => "{$requester->name} requested access to {$module->name}",
                'body' => 'Role: '.($requester->role?->name ?? 'Unassigned').'. Review it in Roles & Permissions.',
                'url' => route('staff.permissions', absolute: false),
                'severity' => 'info',
                'key' => "access:{$requester->id}:{$module->slug}",
            ], $requester);
        });
    }

    /**
     * @param  array<int, string>  $shortages  per-line shortage messages
     */
    public function orderDispatchBlocked(Order $order, array $shortages, ?User $actor): void
    {
        $this->guard('order', function () use ($order, $shortages, $actor): void {
            $this->send($this->usersWithModuleAccess(['inventory'], ['view', 'full']), [
                'type' => 'order.dispatch_blocked',
                'title' => "{$order->order_number} can't be dispatched: stock fell short",
                'body' => implode(' ', array_values($shortages)),
                'url' => route('orders.show', $order, absolute: false),
                'severity' => 'warning',
                'key' => "order:{$order->id}:shortage",
            ], $actor);
        });
    }

    public function invoiceOverdue(Invoice $invoice): void
    {
        $this->guard('invoice', function () use ($invoice): void {
            if (! $this->settings->get('notify.invoice_overdue', true)) {
                return;
            }

            $daysOverdue = (int) $invoice->due_date->diffInDays(today());

            $this->send($this->usersWithModuleAccess(['invoicing'], ['view', 'full']), [
                'type' => 'invoice.overdue',
                'title' => "{$invoice->invoice_number} for {$invoice->customer->name} is overdue",
                'body' => Money::format($invoice->balance()).' outstanding, due '.app(SettingsDate::class)->formatDate($invoice->due_date)
                    .' ('.Plural::count($daysOverdue, 'day').' ago).',
                'url' => route('invoices.show', $invoice, absolute: false),
                'severity' => $daysOverdue > 30 ? 'critical' : 'warning',
                'key' => "invoice:{$invoice->id}:overdue",
            ], null);
        });
    }

    public function largePaymentRecorded(Payment $payment, ?User $actor): void
    {
        $this->guard('payment', function () use ($payment, $actor): void {
            if (! $this->settings->get('notify.large_payment', true)) {
                return;
            }

            $this->send($this->usersWithModuleAccess(['invoicing'], ['view', 'full']), [
                'type' => 'payment.large',
                'title' => Money::format((string) $payment->amount).' received from '.$payment->invoice->customer->name,
                'body' => $payment->methodLabel().($payment->reference ? " {$payment->reference}" : '').' against '.$payment->invoice->invoice_number.'.',
                'url' => route('invoices.show', $payment->invoice, absolute: false),
                'severity' => 'info',
                'key' => "payment:{$payment->id}",
            ], $actor);
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

    public function requestCreated(DepartmentRequest $request, ?User $actor): void
    {
        $this->guard('request', function () use ($request, $actor): void {
            $request->loadMissing('sender:id,name');

            $this->send($this->departmentMembers($request), [
                'type' => 'request.created',
                'title' => $request->type === RequestType::AccessRequest
                    ? "{$request->sender->name} requested access: {$request->subject}"
                    : "New request from {$request->sender->name}: {$request->subject}",
                'body' => "{$request->reference} · {$request->type->label()} · {$request->priority->label()} priority",
                'url' => route('requests.show', $request, absolute: false),
                'severity' => $request->priority === RequestPriority::Urgent ? 'warning' : 'info',
                'key' => "request:{$request->id}:created",
            ], $actor);
        });
    }

    public function requestStatusChanged(DepartmentRequest $request, RequestStatus $from, ?User $actor): void
    {
        $this->guard('request', function () use ($request, $from, $actor): void {
            $request->loadMissing('sender');

            if ($request->sender === null || $request->status === $from) {
                return;
            }

            $this->send(new Collection([$request->sender]), [
                'type' => 'request.status_changed',
                'title' => "{$request->reference} is now {$request->status->label()}",
                'body' => $request->resolution_note ?? "{$request->subject} moved from {$from->label()} to {$request->status->label()}.",
                'url' => route('requests.show', $request, absolute: false),
                'severity' => $request->status === RequestStatus::Declined ? 'warning' : 'info',
                'key' => "request:{$request->id}:status:{$request->status->value}",
            ], $actor);
        });
    }

    /**
     * Comments go to the other side of the thread: the sender, or the department (its assignee when set).
     */
    public function requestCommented(DepartmentRequest $request, RequestComment $comment, User $author): void
    {
        $this->guard('request', function () use ($request, $comment, $author): void {
            $request->loadMissing(['sender', 'assignee']);
            $recipients = $author->id === $request->from_user_id
                ? ($request->assignee !== null ? new Collection([$request->assignee]) : $this->departmentMembers($request))
                : new Collection(array_filter([$request->sender]));

            $this->send($recipients, [
                'type' => 'request.commented',
                'title' => "{$author->name} commented on {$request->reference}",
                'body' => mb_strimwidth($comment->body, 0, 140, '…'),
                'url' => route('requests.show', $request, absolute: false),
                'severity' => 'info',
                'key' => "request:{$request->id}:comment:{$comment->id}",
            ], $author);
        });
    }

    public function requestOverdue(DepartmentRequest $request): void
    {
        $this->guard('request', function () use ($request): void {
            $request->loadMissing('assignee');

            $this->send($request->assignee !== null ? new Collection([$request->assignee]) : $this->departmentMembers($request), [
                'type' => 'request.overdue',
                'title' => "{$request->reference} is overdue",
                'body' => "{$request->subject} was due ".app(SettingsDate::class)->formatDate($request->due_date).'.',
                'url' => route('requests.show', $request, absolute: false),
                'severity' => 'warning',
                'key' => "request:{$request->id}:overdue",
            ], null);
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function departmentMembers(DepartmentRequest $request): Collection
    {
        return User::query()
            ->where('status', '!=', 'suspended')
            ->where('role_id', $request->to_role_id)
            ->get();
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
