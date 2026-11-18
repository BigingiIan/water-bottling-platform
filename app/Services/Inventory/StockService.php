<?php

namespace App\Services\Inventory;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\NonIntegerStockLevelException;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class StockService
{
    private const MOVEMENT_TYPES = [
        'production',
        'sale',
        'adjustment',
        'leakage_writeoff',
        'return',
        'stock_in',
    ];

    public function __construct(
        private SettingsService $settings,
        private NotificationService $notifications,
    ) {}

    public function totalForProduct(Product $product): float
    {
        return (float) $product->warehouseStock()->sum('current_level');
    }

    public function statusForProduct(Product $product, ?float $totalUnits = null): string
    {
        $total = $totalUnits ?? $this->totalForProduct($product);

        if ($total <= 0) {
            return 'out';
        }

        $reorderThreshold = $product->reorder_threshold;

        if (! is_numeric($reorderThreshold) || (float) $reorderThreshold <= 0) {
            return 'ok';
        }

        $threshold = (float) $reorderThreshold;

        $criticalPercent = $this->settings->get('inventory.critical_percent', 50);

        if ($total < $threshold * $criticalPercent / 100) {
            return 'critical';
        }

        return $total < $threshold ? 'low' : 'ok';
    }

    public function coverDays(Product $product): ?float
    {
        $forecast = $product->forecastRecords()
            ->whereDate('forecast_date', today()->addDay())
            ->where('horizon_days', 1)
            ->latestGenerated()
            ->first();

        if ($forecast === null || (float) $forecast->predicted_demand <= 0) {
            return null;
        }

        return $this->totalForProduct($product) / (float) $forecast->predicted_demand;
    }

    public function applyMovement(
        WarehouseStock $stock,
        string $type,
        int $change,
        ?string $reference,
        ?string $reason,
        ?User $by,
    ): StockMovement {
        if (! in_array($type, self::MOVEMENT_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported stock movement type.');
        }

        $productTotalBefore = null;

        $movement = DB::transaction(function () use ($stock, $type, $change, $reference, $reason, $by, &$productTotalBefore): StockMovement {
            $lockedStock = WarehouseStock::query()
                ->whereKey($stock->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $productTotalBefore = (float) WarehouseStock::query()
                ->where('product_id', $lockedStock->product_id)
                ->sum('current_level');
            $oldLevel = (float) $lockedStock->current_level;
            $newLevel = $oldLevel + $change;

            if ($newLevel < 0) {
                throw new InsufficientStockException;
            }

            if (floor($newLevel) !== $newLevel) {
                throw new NonIntegerStockLevelException;
            }

            $updatedAt = now();
            $lockedStock->current_level = $newLevel;
            $lockedStock->last_updated = $updatedAt;
            $lockedStock->save();

            $movement = StockMovement::create([
                'warehouse_stock_id' => $lockedStock->id,
                'product_id' => $lockedStock->product_id,
                'type' => $type,
                'quantity_change' => $change,
                'level_after' => (int) $newLevel,
                'reference' => $reference,
                'reason' => $reason,
                'user_id' => $by?->id,
            ]);

            AuditLog::create([
                'user_id' => $by?->id,
                'action' => 'inventory.stock_movement',
                'table_name' => 'warehouse_stock',
                'old_values' => ['current_level' => $oldLevel],
                'new_values' => [
                    'current_level' => $newLevel,
                    'stock_movement_id' => $movement->id,
                    'type' => $type,
                    'quantity_change' => $change,
                ],
            ]);

            $stock->current_level = $newLevel;
            $stock->last_updated = $updatedAt;

            return $movement;
        });

        $this->notifyStockTransition($movement, (float) $productTotalBefore, $change, $by);

        return $movement;
    }

    private function notifyStockTransition(StockMovement $movement, float $productTotalBefore, int $change, ?User $by): void
    {
        try {
            $product = Product::query()->find($movement->product_id);

            if ($product === null) {
                return;
            }

            $this->notifications->stockStatusChanged(
                $product,
                $this->statusForProduct($product, $productTotalBefore),
                $this->statusForProduct($product, $productTotalBefore + $change),
                $by,
            );
        } catch (Throwable $exception) {
            Log::error('Failed to evaluate stock notification.', ['exception' => $exception]);
        }
    }

    // TODO: Wire production completion and sales creation to this service only after the workflow is approved.
}
