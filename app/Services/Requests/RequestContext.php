<?php

namespace App\Services\Requests;

use App\Models\DepartmentRequest;
use App\Models\LeakageReport;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionSchedule;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Support\LeakageReportFlag;
use App\Support\ModuleAccess;
use App\Support\SettingsDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Builds the context snapshot stored with a request and resolves the live link to the related record.
 *
 * Snapshots are built on the server from a fixed list of safe fields per record type, and only when the
 * sender can view the record's module, so a request never carries more than its sender could see. Money
 * is never included.
 */
class RequestContext
{
    /**
     * @var array<string, array{label: string, module: string, model: class-string<Model>, type: RequestType}>
     */
    public const RELATED_TYPES = [
        'order' => ['label' => 'Order', 'module' => 'orders', 'model' => Order::class, 'type' => RequestType::StockCheck],
        'production_run' => ['label' => 'Production run', 'module' => 'production', 'model' => ProductionSchedule::class, 'type' => RequestType::ProductionRequest],
        'batch' => ['label' => 'Batch', 'module' => 'inventory', 'model' => ProductionBatch::class, 'type' => RequestType::DataCorrection],
        'leakage_report' => ['label' => 'Leakage report', 'module' => 'inventory', 'model' => LeakageReport::class, 'type' => RequestType::LeakageReview],
        'product' => ['label' => 'Product', 'module' => 'inventory', 'model' => Product::class, 'type' => RequestType::StockCheck],
        'stock_line' => ['label' => 'Stock line', 'module' => 'inventory', 'model' => WarehouseStock::class, 'type' => RequestType::StockCheck],
    ];

    public function __construct(
        private ModuleAccess $moduleAccess,
        private SettingsDate $settingsDate,
    ) {}

    public function isKnownType(?string $relatedType): bool
    {
        return $relatedType !== null && array_key_exists($relatedType, self::RELATED_TYPES);
    }

    public function suggestedType(string $relatedType): RequestType
    {
        return self::RELATED_TYPES[$relatedType]['type'];
    }

    /**
     * Loads the record for a snapshot, refusing records whose module the sender cannot view.
     */
    public function recordFor(User $sender, string $relatedType, int $relatedId): Model
    {
        abort_unless($this->isKnownType($relatedType), 422, 'Unknown related record type.');
        $this->moduleAccess->authorize($sender, self::RELATED_TYPES[$relatedType]['module']);

        return self::RELATED_TYPES[$relatedType]['model']::query()->findOrFail($relatedId);
    }

    /**
     * @return array{type: string, label: string, fields: list<array{label: string, value: string}>, captured_at: string}
     */
    public function snapshotFor(User $sender, string $relatedType, int $relatedId): array
    {
        $record = $this->recordFor($sender, $relatedType, $relatedId);

        [$label, $fields] = match ($relatedType) {
            'order' => $this->orderFields($record),
            'production_run' => $this->productionRunFields($record),
            'batch' => $this->batchFields($record),
            'leakage_report' => $this->leakageReportFields($record),
            'product' => $this->productFields($record),
            'stock_line' => $this->stockLineFields($record),
        };

        return [
            'type' => $relatedType,
            'label' => $label,
            'fields' => array_values(array_map(
                fn (string $fieldLabel, string $value): array => ['label' => $fieldLabel, 'value' => $value],
                array_keys($fields),
                $fields,
            )),
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The link to the live record, only for viewers who can open its module.
     */
    public function liveUrlFor(User $viewer, DepartmentRequest $request): ?string
    {
        if (! $this->isKnownType($request->related_type) || $request->related_id === null) {
            return null;
        }

        if (! $this->moduleAccess->can($viewer, self::RELATED_TYPES[$request->related_type]['module'])) {
            return null;
        }

        $record = self::RELATED_TYPES[$request->related_type]['model']::query()->find($request->related_id);

        if ($record === null) {
            return null;
        }

        return match ($request->related_type) {
            'order' => route('orders.show', $record),
            'production_run' => route('production.runs', ['run' => $record->getKey()]),
            'batch' => route('inventory.overview', ['tab' => 'batches']),
            'leakage_report' => route('inventory.overview', ['tab' => 'leakage']),
            'product' => route('inventory.overview', ['productFilter' => $record->getKey()]),
            'stock_line' => route('inventory.overview', ['productFilter' => $record->product_id]),
        };
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function orderFields(Order $order): array
    {
        $order->loadMissing(['customer:id,name', 'items:id,order_id,product_id,quantity', 'items.product:id,unit_type']);

        return ["Order {$order->order_number}", [
            'Customer' => (string) $order->customer?->name,
            'Items' => $order->items
                ->map(fn ($item): string => ($item->product?->unit_type ?? 'Product').' × '.number_format($item->quantity))
                ->implode(', ') ?: 'No items',
            'Status' => $order->status->label(),
            'Requested for' => $order->requested_date ? $this->settingsDate->formatDate($order->requested_date) : 'Not set',
        ]];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function productionRunFields(ProductionSchedule $run): array
    {
        $run->loadMissing('productionLine:id,name')->loadSum('logs', 'units_logged');

        return ["Production run {$run->product_sku} on ".($run->productionLine?->name ?? 'a line'), [
            'Scheduled' => $this->settingsDate->formatDate($run->scheduled_date).' '.mb_substr((string) $run->scheduled_time, 0, 5),
            'Status' => ucfirst((string) $run->status),
            'Output' => number_format((int) ($run->logs_sum_units_logged ?? 0)).' of '.number_format((int) $run->target_units).' units',
        ]];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function batchFields(ProductionBatch $batch): array
    {
        return ['Batch '.$this->batchCode($batch->id), [
            'Date' => $this->settingsDate->formatDate($batch->batch_date),
            'Status' => ucfirst((string) $batch->status),
            'Bottled volume' => number_format((float) $batch->bottled_volume, 1).' L',
        ]];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function leakageReportFields(LeakageReport $report): array
    {
        $report->loadMissing('product:id,unit_type');

        return ["Leakage report #{$report->id}", [
            'Product' => $report->product?->unit_type ?? ($report->batch_id ? 'Batch '.$this->batchCode($report->batch_id) : 'Not recorded'),
            'Date' => $this->settingsDate->formatDate($report->report_date),
            'Flag' => LeakageReportFlag::fromSeverity($report->severity),
            'Loss' => $report->loss_percent !== null ? number_format((float) $report->loss_percent, 1).'%' : 'Not graded',
            'Status' => ucfirst((string) $report->status),
        ]];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function productFields(Product $product): array
    {
        return ["Product {$product->unit_type}", [
            'Name' => $product->name,
            'Units on hand' => number_format((float) $product->warehouseStock()->sum('current_level')),
            'Reorder threshold' => number_format((float) $product->reorder_threshold),
        ]];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function stockLineFields(WarehouseStock $stockLine): array
    {
        $stockLine->loadMissing('product:id,unit_type');

        return ['Stock line '.($stockLine->product?->unit_type ?? 'product').' at '.($stockLine->warehouse_location ?: 'unassigned location'), [
            'Batch' => $this->batchCode($stockLine->batch_id),
            'Units on hand' => number_format((float) $stockLine->current_level),
            'As of' => $this->settingsDate->formatDateTime(Carbon::now()),
        ]];
    }

    private function batchCode(?int $batchId): string
    {
        return 'B-'.str_pad((string) $batchId, 5, '0', STR_PAD_LEFT);
    }
}
