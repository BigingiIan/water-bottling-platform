<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\Orders\Money;
use App\Support\Dashboard\DashboardWidgetRegistry;
use App\Support\Plural;
use App\Support\SettingsDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The most urgent items across the modules a user can see, worst and oldest first.
 */
class AttentionItems
{
    public const LIMIT = 6;

    /**
     * Lower ranks sort first.
     *
     * @var array<string, int>
     */
    public const SEVERITY_RANKS = ['critical' => 0, 'high' => 1, 'warning' => 2];

    public function __construct(
        private DashboardWidgetRegistry $registry,
        private DashboardMetrics $metrics,
        private SettingsDate $settingsDate,
    ) {}

    /**
     * @return list<array{key: string, severity: string, title: string, detail: string, url: string, occurredAt: CarbonInterface}>
     */
    public function for(User $user): array
    {
        $items = [
            ...$this->registry->canAccessModule($user, 'inventory') ? $this->stockItems() : [],
            ...$this->registry->canAccessModule($user, 'inventory') ? $this->leakageItems() : [],
            ...$this->registry->canAccessModule($user, 'orders') ? $this->orderItems() : [],
            ...$this->registry->canAccessModule($user, 'invoicing') ? $this->invoiceItems() : [],
            ...$this->requestItems($user),
        ];

        usort($items, fn (array $a, array $b): int => [self::SEVERITY_RANKS[$a['severity']], $a['occurredAt']->getTimestamp()]
            <=> [self::SEVERITY_RANKS[$b['severity']], $b['occurredAt']->getTimestamp()]);

        return array_slice($items, 0, self::LIMIT);
    }

    /**
     * @return list<array{key: string, severity: string, title: string, detail: string, url: string, occurredAt: CarbonInterface}>
     */
    private function stockItems(): array
    {
        return $this->metrics->stockAlerts()
            ->map(fn (array $alert): array => [
                'key' => 'stock-'.$alert['product']->id,
                'severity' => $alert['status'] === 'low' ? 'warning' : 'critical',
                'title' => match ($alert['status']) {
                    'out' => "{$alert['product']->unit_type} is out of stock",
                    'critical' => "{$alert['product']->unit_type} stock is critical",
                    default => "{$alert['product']->unit_type} stock is low",
                },
                'detail' => Plural::count($alert['units'], 'unit').' on hand · reorder at '.number_format((float) $alert['product']->reorder_threshold),
                'url' => route('inventory.overview', ['productFilter' => $alert['product']->id]),
                'occurredAt' => now(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{key: string, severity: string, title: string, detail: string, url: string, occurredAt: CarbonInterface}>
     */
    private function leakageItems(): array
    {
        return $this->metrics->leakageToInvestigate(self::LIMIT)
            ->map(fn ($report): array => [
                'key' => 'leakage-'.$report->id,
                'severity' => 'critical',
                'title' => 'Leakage report needs investigation',
                'detail' => trim(($report->product?->unit_type ?? 'Unassigned product')
                    .($report->loss_percent !== null ? ' · '.number_format((float) $report->loss_percent, 1).'% loss' : '')
                    .' · '.$this->settingsDate->formatDate($report->report_date)),
                'url' => route('inventory.overview', ['tab' => 'leakage']),
                'occurredAt' => Carbon::parse($report->report_date),
            ])
            ->all();
    }

    /**
     * @return list<array{key: string, severity: string, title: string, detail: string, url: string, occurredAt: CarbonInterface}>
     */
    private function orderItems(): array
    {
        return $this->metrics->undispatchableOrders()
            ->take(self::LIMIT)
            ->map(fn (array $blocked): array => [
                'key' => 'order-'.$blocked['order']->id,
                'severity' => 'high',
                'title' => "{$blocked['order']->order_number} can't be dispatched",
                'detail' => 'Short of '.implode(', ', $blocked['shortfalls']),
                'url' => route('orders.show', $blocked['order']),
                'occurredAt' => Carbon::parse($blocked['order']->requested_date ?? $blocked['order']->order_date),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{key: string, severity: string, title: string, detail: string, url: string, occurredAt: CarbonInterface}>
     */
    private function invoiceItems(): array
    {
        return $this->metrics->overdueInvoices(self::LIMIT)
            ->map(fn ($invoice): array => [
                'key' => 'invoice-'.$invoice->id,
                'severity' => 'high',
                'title' => "{$invoice->displayNumber()} is overdue",
                'detail' => ($invoice->customer?->name ?? 'Customer').' · '.Money::format($invoice->balance()).' due '.$this->settingsDate->formatDate($invoice->due_date),
                'url' => route('invoices.show', $invoice),
                'occurredAt' => Carbon::parse($invoice->due_date),
            ])
            ->all();
    }

    /**
     * Unread cross-department requests, once that feature exists.
     *
     * @return list<array{key: string, severity: string, title: string, detail: string, url: string, occurredAt: CarbonInterface}>
     */
    private function requestItems(User $user): array
    {
        $requestModel = DashboardWidgetRegistry::REQUEST_MODEL;

        if (! class_exists($requestModel) || ! $this->registry->canAccessModule($user, 'requests')) {
            return [];
        }

        return $requestModel::query()
            ->unreadFor($user)
            ->oldest()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn ($request): array => [
                'key' => 'request-'.$request->getKey(),
                'severity' => 'warning',
                'title' => 'Unread request: '.($request->subject ?? 'Request'),
                'detail' => 'Sent '.$request->created_at?->diffForHumans(),
                'url' => method_exists($request, 'url') ? $request->url() : route('notifications.index'),
                'occurredAt' => $request->created_at ?? now(),
            ])
            ->all();
    }
}
