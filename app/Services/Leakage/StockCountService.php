<?php

namespace App\Services\Leakage;

use App\Models\Product;
use App\Models\StockCount;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records a physical count for a product at a location, corrects the system level to match it,
 * and checks the difference for shrinkage (Stage B).
 */
class StockCountService
{
    public function __construct(
        private LeakageDetector $detector,
        private LeakageReporter $reporter,
        private StockService $stock,
    ) {}

    /**
     * @return array{count: StockCount, assessment: ShrinkageAssessment}
     */
    public function record(Product $product, ?string $location, int $countedUnits, ?string $note, User $user): array
    {
        if ($countedUnits < 0) {
            throw new InvalidArgumentException('Counted units cannot be negative.');
        }

        return DB::transaction(function () use ($product, $location, $countedUnits, $note, $user): array {
            $lines = WarehouseStock::query()
                ->select('warehouse_stock.*')
                ->join('production_batches', 'production_batches.id', '=', 'warehouse_stock.batch_id')
                ->where('warehouse_stock.product_id', $product->id)
                ->when($location === null, fn ($query) => $query->whereNull('warehouse_stock.warehouse_location'), fn ($query) => $query->where('warehouse_stock.warehouse_location', $location))
                ->orderBy('production_batches.batch_date')
                ->orderBy('warehouse_stock.id')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw new InvalidArgumentException("There is no {$product->unit_type} stock line at this location.");
            }

            $previous = StockCount::query()
                ->where('product_id', $product->id)
                ->where('warehouse_location', $location)
                ->latest('id')
                ->first();
            $assessment = $this->detector->evaluateCount($product, $location, $countedUnits, $previous);

            $count = StockCount::query()->create([
                'product_id' => $product->id,
                'warehouse_location' => $location,
                'counted_units' => $countedUnits,
                'opening_units' => $assessment->openingUnits,
                'production_in' => $assessment->productionIn,
                'sales_out' => $assessment->salesOut,
                'adjustments_net' => $assessment->adjustmentsNet,
                'expected_units' => $assessment->expectedUnits,
                'system_units' => $assessment->systemUnits,
                'shrinkage_units' => $assessment->shrinkageUnits,
                'previous_count_id' => $previous?->id,
                'user_id' => $user->id,
                'note' => $note,
            ]);

            $this->correctLevels($lines->all(), $countedUnits - $assessment->systemUnits, $count, $note, $user);
            $count->update(['last_movement_id' => DB::table('stock_movements')->whereIn('warehouse_stock_id', $lines->pluck('id'))->max('id')]);
            $this->reporter->recordStockCount($count, $product, $assessment, $user);

            return ['count' => $count->refresh(), 'assessment' => $assessment];
        });
    }

    /**
     * Losses come out of the oldest batch first; gains go onto the newest line.
     *
     * @param  array<int, WarehouseStock>  $lines  oldest batch first
     */
    private function correctLevels(array $lines, int $difference, StockCount $count, ?string $note, User $user): void
    {
        if ($difference === 0) {
            return;
        }

        $reference = LeakageDetector::COUNT_REFERENCE_PREFIX.$count->id;
        $reason = 'Stock count'.($note ? ": {$note}" : '');

        if ($difference > 0) {
            $this->stock->applyMovement(end($lines), 'adjustment', $difference, $reference, $reason, $user);

            return;
        }

        $remaining = -$difference;

        foreach ($lines as $line) {
            $take = min($remaining, (int) $line->current_level);

            if ($take > 0) {
                $this->stock->applyMovement($line, 'adjustment', -$take, $reference, $reason, $user);
                $remaining -= $take;
            }

            if ($remaining === 0) {
                break;
            }
        }
    }
}
