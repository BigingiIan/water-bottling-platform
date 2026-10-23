<?php

namespace App\Livewire;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\NonIntegerStockLevelException;
use App\Models\LeakageReport;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Inventory\StockService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use App\Support\LeakageReportFlag;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Stock Overview', 'breadcrumbSection' => 'Inventory', 'activeItem' => 'Stock Overview'])]
class InventoryOverview extends Component
{
    use WithPagination;

    #[Url(as: 'tab', except: 'stock')]
    public string $activeTab = 'stock';

    #[Url(except: '')]
    public string $batchDateFrom = '';

    #[Url(except: '')]
    public string $batchDateTo = '';

    #[Url(except: '')]
    public string $batchStatus = '';

    #[Url(except: '')]
    public string $leakageFlagFilter = '';

    #[Url(except: '')]
    public string $movementTypeFilter = '';

    #[Url(except: '')]
    public string $movementProductFilter = '';

    #[Url(except: '')]
    public string $movementDateFrom = '';

    #[Url(except: '')]
    public string $movementDateTo = '';

    #[Url(except: '')]
    public string $productFilter = '';

    #[Url(except: '')]
    public string $locationFilter = '';

    #[Url(except: '')]
    public string $statusFilter = '';

    public bool $adjustmentOpen = false;

    public bool $stockInOpen = false;

    public ?int $adjustmentStockId = null;

    public string $adjustmentReasonType = 'count_correction';

    public string $adjustmentChange = '';

    public string $adjustmentNote = '';

    public string $stockInProductId = '';

    public string $stockInBatchId = '';

    public string $stockInLocation = '';

    public string $stockInQuantity = '';

    public bool $leakageReportOpen = false;

    public string $leakageBatchId = '';

    public string $leakageExpectedVolume = '';

    public string $leakageActualVolume = '';

    public function mount(): void
    {
        if (! in_array($this->activeTab, ['stock', 'batches', 'leakage', 'movements'], true)) {
            $this->activeTab = 'stock';
        }

        $defaultFrom = now()->subDays(13)->toDateString();
        $today = now()->toDateString();
        $this->batchDateFrom = $this->validDate($this->batchDateFrom) ?? $defaultFrom;
        $this->batchDateTo = $this->validDate($this->batchDateTo) ?? $today;
        $this->movementDateFrom = $this->validDate($this->movementDateFrom) ?? now()->subDays(6)->toDateString();
        $this->movementDateTo = $this->validDate($this->movementDateTo) ?? $today;
    }

    public function updatedActiveTab(): void
    {
        if (! in_array($this->activeTab, ['stock', 'batches', 'leakage', 'movements'], true)) {
            $this->activeTab = 'stock';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, [
            'batchDateFrom',
            'batchDateTo',
            'batchStatus',
        ], true)) {
            $this->resetPage(pageName: 'batchesPage');
        }

        if ($property === 'leakageFlagFilter') {
            $this->resetPage(pageName: 'leakagePage');
        }

        if (in_array($property, [
            'movementTypeFilter',
            'movementProductFilter',
            'movementDateFrom',
            'movementDateTo',
        ], true)) {
            $this->resetPage(pageName: 'movementsPage');
        }
    }

    public function openLeakageReportModal(): void
    {
        $this->authorizeInventoryWrite();
        $this->resetValidation();
        $this->reset(['leakageBatchId', 'leakageExpectedVolume', 'leakageActualVolume']);
        $this->leakageReportOpen = true;
    }

    public function closeLeakageReportModal(): void
    {
        $this->leakageReportOpen = false;
        $this->reset(['leakageBatchId', 'leakageExpectedVolume', 'leakageActualVolume']);
        $this->resetValidation();
    }

    public function saveLeakageReport(): void
    {
        $this->authorizeInventoryWrite();
        $validated = $this->validate([
            'leakageBatchId' => ['required', 'integer', Rule::exists('production_batches', 'id')],
            'leakageExpectedVolume' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'leakageActualVolume' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
        ]);

        $expected = (float) $validated['leakageExpectedVolume'];
        $actual = (float) $validated['leakageActualVolume'];
        $report = LeakageReport::create([
            'batch_id' => $validated['leakageBatchId'],
            'report_date' => today()->toDateString(),
            'expected_volume' => $expected,
            'actual_volume' => $actual,
            'discrepancy' => $actual - $expected,
        ]);
        app(NotificationService::class)->leakageReportFlagged($report, auth()->user());

        session()->flash('inventorySuccess', 'Leakage report recorded.');
        $this->closeLeakageReportModal();
    }

    public function openAdjustmentModal(?int $stockId = null): void
    {
        $this->authorizeInventoryWrite();

        $this->resetValidation();
        $this->reset(['adjustmentChange', 'adjustmentNote']);
        $this->adjustmentReasonType = 'count_correction';

        if ($stockId !== null) {
            WarehouseStock::query()->findOrFail($stockId);
        }

        $this->adjustmentStockId = $stockId;
        $this->adjustmentOpen = true;
        $this->stockInOpen = false;
    }

    public function closeAdjustmentModal(): void
    {
        $this->adjustmentOpen = false;
        $this->reset(['adjustmentStockId', 'adjustmentChange', 'adjustmentNote']);
        $this->resetValidation();
    }

    public function saveAdjustment(): void
    {
        $user = $this->authorizeInventoryWrite();
        $validated = $this->validate([
            'adjustmentStockId' => ['required', 'integer', Rule::exists('warehouse_stock', 'id')],
            'adjustmentReasonType' => ['required', Rule::in([
                'count_correction',
                'damaged',
                'leakage_writeoff',
                'customer_return',
            ])],
            'adjustmentChange' => ['required', 'integer', 'not_in:0'],
            'adjustmentNote' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'adjustmentChange.not_in' => 'Enter a non-zero quantity change.',
            'adjustmentNote.min' => 'The note must be at least 5 characters.',
        ]);

        $movementType = match ($validated['adjustmentReasonType']) {
            'leakage_writeoff' => 'leakage_writeoff',
            'customer_return' => 'return',
            default => 'adjustment',
        };

        try {
            $stock = WarehouseStock::query()->findOrFail($validated['adjustmentStockId']);
            app(StockService::class)->applyMovement(
                $stock,
                $movementType,
                (int) $validated['adjustmentChange'],
                null,
                trim($validated['adjustmentNote']),
                $user,
            );
        } catch (InsufficientStockException|NonIntegerStockLevelException $exception) {
            $message = $exception instanceof InsufficientStockException
                ? 'The adjustment cannot reduce this stock line below zero.'
                : $exception->getMessage();
            $this->addError('adjustmentChange', $message);

            return;
        }

        session()->flash('inventorySuccess', 'Stock adjustment recorded.');
        $this->closeAdjustmentModal();
    }

    public function openStockInModal(): void
    {
        $this->authorizeInventoryWrite();
        $this->resetValidation();
        $this->reset(['stockInProductId', 'stockInBatchId', 'stockInLocation', 'stockInQuantity']);
        $this->stockInOpen = true;
        $this->adjustmentOpen = false;
    }

    public function closeStockInModal(): void
    {
        $this->stockInOpen = false;
        $this->reset(['stockInProductId', 'stockInBatchId', 'stockInLocation', 'stockInQuantity']);
        $this->resetValidation();
    }

    public function saveStockIn(): void
    {
        $user = $this->authorizeInventoryWrite();
        $validated = $this->validate([
            'stockInProductId' => ['required', 'integer', Rule::exists('products', 'id')],
            'stockInBatchId' => ['required', 'integer', Rule::exists('production_batches', 'id')],
            'stockInLocation' => ['required', 'string', 'max:255'],
            'stockInQuantity' => ['required', 'integer', 'min:1'],
        ]);

        try {
            DB::transaction(function () use ($validated, $user): void {
                $product = Product::query()->lockForUpdate()->findOrFail($validated['stockInProductId']);
                ProductionBatch::query()->lockForUpdate()->findOrFail($validated['stockInBatchId']);

                $stock = WarehouseStock::query()
                    ->where('batch_id', $validated['stockInBatchId'])
                    ->where('product_id', $product->id)
                    ->where('warehouse_location', trim($validated['stockInLocation']))
                    ->lockForUpdate()
                    ->first();

                if ($stock === null) {
                    $stock = WarehouseStock::create([
                        'batch_id' => $validated['stockInBatchId'],
                        'product_id' => $product->id,
                        'warehouse_location' => trim($validated['stockInLocation']),
                        'current_level' => 0,
                    ]);
                }

                app(StockService::class)->applyMovement(
                    $stock,
                    'stock_in',
                    (int) $validated['stockInQuantity'],
                    null,
                    'Stock received outside a production run.',
                    $user,
                );
            });
        } catch (InsufficientStockException|NonIntegerStockLevelException $exception) {
            $this->addError('stockInQuantity', $exception->getMessage());

            return;
        }

        session()->flash('inventorySuccess', 'Stock-in recorded.');
        $this->closeStockInModal();
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        $moduleAccess = app(ModuleAccess::class);
        $moduleAccess->authorize($user, 'inventory');

        $canWriteInventory = $moduleAccess->can($user, 'inventory', write: true);
        $settings = app(SettingsService::class);
        $forecastEnabled = $settings->get('forecast.enabled', true);
        $criticalPercent = $settings->get('inventory.critical_percent', 50);
        $coverWarningDays = $settings->get('inventory.cover_warning_days', 1.0);
        $productQuery = Product::query()->orderBy('name');

        if ($this->activeTab === 'stock' && $forecastEnabled) {
            $productQuery->with(['forecastRecords' => function (HasMany $query): void {
                $query->whereDate('forecast_date', today()->addDay())
                    ->where('horizon_days', 1)
                    ->orderByDesc('model_version')
                    ->orderByDesc('id');
            }]);
        }

        $products = in_array($this->activeTab, ['stock', 'movements'], true)
            ? $productQuery->get()
            : collect();
        $batches = $this->activeTab === 'stock' || ($this->activeTab === 'leakage' && $this->leakageReportOpen)
            ? ProductionBatch::query()->orderByDesc('batch_date')->orderByDesc('id')->get()
            : collect();
        $attentionQuery = LeakageReport::query()
            ->whereDate('report_date', '>=', now()->subDays(13)->toDateString())
            ->whereDate('report_date', '<=', now()->toDateString());
        LeakageReportFlag::applyFilter($attentionQuery, 'Attention');
        $leakageAttentionCount = $attentionQuery->count();

        $viewData = [
            'canWriteInventory' => $canWriteInventory,
            'products' => $products,
            'batches' => $batches,
            'leakageAttentionCount' => $leakageAttentionCount,
            'forecastEnabled' => $forecastEnabled,
        ];

        if ($this->activeTab === 'batches') {
            $batchQuery = ProductionBatch::query()
                ->with('user')
                ->whereDate('batch_date', '>=', $this->batchDateFrom)
                ->whereDate('batch_date', '<=', $this->batchDateTo)
                ->when(in_array($this->batchStatus, ['pending', 'in_progress', 'completed', 'rejected'], true), function (Builder $query): void {
                    $query->where('status', $this->batchStatus);
                })
                ->orderByDesc('batch_date')
                ->orderByDesc('id');

            $viewData['batchRows'] = $batchQuery->paginate($this->pageSize(15), pageName: 'batchesPage');

            return view('livewire.inventory-overview', $viewData);
        }

        if ($this->activeTab === 'leakage') {
            $leakageQuery = LeakageReport::query()
                ->with('batch')
                ->when(in_array($this->leakageFlagFilter, ['Normal', 'Review', 'Investigate'], true), function (Builder $query): void {
                    LeakageReportFlag::applyFilter($query, $this->leakageFlagFilter);
                })
                ->orderByDesc('report_date')
                ->orderByDesc('id');

            $viewData['leakageReports'] = $leakageQuery->paginate($this->pageSize(15), pageName: 'leakagePage');

            return view('livewire.inventory-overview', $viewData);
        }

        if ($this->activeTab === 'movements') {
            $movementQuery = StockMovement::query()
                ->with(['product', 'user'])
                ->when(in_array($this->movementTypeFilter, [
                    'production',
                    'sale',
                    'adjustment',
                    'leakage_writeoff',
                    'return',
                    'stock_in',
                ], true), function (Builder $query): void {
                    $query->where('type', $this->movementTypeFilter);
                })
                ->when($this->movementProductFilter !== '', function (Builder $query): void {
                    $query->where('product_id', (int) $this->movementProductFilter);
                })
                ->when($this->movementDateFrom !== '', function (Builder $query): void {
                    $query->whereDate('created_at', '>=', $this->movementDateFrom);
                })
                ->when($this->movementDateTo !== '', function (Builder $query): void {
                    $query->whereDate('created_at', '<=', $this->movementDateTo);
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $viewData['movementRows'] = $movementQuery->paginate($this->pageSize(25), pageName: 'movementsPage');

            return view('livewire.inventory-overview', $viewData);
        }

        $totalsQuery = DB::table('warehouse_stock')
            ->select('product_id')
            ->selectRaw('SUM(current_level) as total_units')
            ->groupBy('product_id');
        $totals = (clone $totalsQuery)->pluck('total_units', 'product_id');

        $stockService = app(StockService::class);
        $productCards = $products->map(function (Product $product) use ($stockService, $totals, $forecastEnabled, $coverWarningDays): array {
            $totalUnits = (float) ($totals[$product->id] ?? 0);
            $forecast = $forecastEnabled ? $product->forecastRecords->first() : null;
            $forecastDemand = $forecast === null ? null : (float) $forecast->predicted_demand;
            $coverDays = $forecastDemand !== null && $forecastDemand > 0
                ? $totalUnits / $forecastDemand
                : null;
            $coverWarning = $coverDays !== null && $coverDays < $coverWarningDays;

            return [
                'product' => $product,
                'totalUnits' => $totalUnits,
                'status' => $stockService->statusForProduct($product, $totalUnits),
                'coverDays' => $coverDays,
                'forecastDemand' => $forecastDemand,
                'coverWarning' => $coverWarning,
            ];
        });

        $stockLines = WarehouseStock::query()
            ->with(['product', 'batch'])
            ->when($this->productFilter !== '', function (Builder $query): void {
                $query->where('product_id', (int) $this->productFilter);
            })
            ->when($this->locationFilter !== '', function (Builder $query): void {
                $query->where('warehouse_location', $this->locationFilter);
            })
            ->when(in_array($this->statusFilter, ['ok', 'low', 'critical'], true), function (Builder $query) use ($totalsQuery, $criticalPercent): void {
                $query->whereHas('product', function (Builder $productQuery) use ($totalsQuery, $criticalPercent): void {
                    $productQuery->leftJoinSub(
                        clone $totalsQuery,
                        'inventory_totals',
                        'inventory_totals.product_id',
                        '=',
                        'products.id',
                    );

                    $total = 'COALESCE(inventory_totals.total_units, 0)';

                    match ($this->statusFilter) {
                        'critical' => $productQuery->whereRaw("products.reorder_threshold > 0 AND {$total} < products.reorder_threshold * ? / 100", [$criticalPercent]),
                        'low' => $productQuery->whereRaw("products.reorder_threshold > 0 AND {$total} < products.reorder_threshold AND {$total} >= products.reorder_threshold * ? / 100", [$criticalPercent]),
                        'ok' => $productQuery->where(function (Builder $query) use ($total): void {
                            $query->whereNull('products.reorder_threshold')
                                ->orWhere('products.reorder_threshold', '<=', 0)
                                ->orWhereRaw("{$total} >= products.reorder_threshold");
                        }),
                    };
                });
            })
            ->orderBy('product_id')
            ->orderBy('batch_id')
            ->get();

        $adjustableStockLines = WarehouseStock::query()
            ->with(['product', 'batch'])
            ->orderBy('product_id')
            ->orderBy('batch_id')
            ->get();

        $locations = WarehouseStock::query()
            ->whereNotNull('warehouse_location')
            ->where('warehouse_location', '!=', '')
            ->distinct()
            ->orderBy('warehouse_location')
            ->pluck('warehouse_location');

        $attentionProducts = $productCards
            ->filter(fn (array $card): bool => in_array($card['status'], ['low', 'critical'], true) || $card['coverWarning'])
            ->sortBy(fn (array $card): int => match ($card['status']) {
                'critical' => 0,
                'low' => 1,
                default => 2,
            })
            ->values();

        return view('livewire.inventory-overview', array_merge($viewData, [
            'productCards' => $productCards,
            'statusByProduct' => $productCards->keyBy(fn (array $card): int => $card['product']->id),
            'stockLines' => $stockLines,
            'adjustableStockLines' => $adjustableStockLines,
            'selectedAdjustmentStock' => $adjustableStockLines->firstWhere('id', $this->adjustmentStockId),
            'locations' => $locations,
            'attentionProducts' => $attentionProducts,
            'recentMovements' => StockMovement::query()
                ->with(['product', 'user'])
                ->latest('created_at')
                ->latest('id')
                ->limit(5)
                ->get(),
        ]));
    }

    private function validDate(string $date): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed->format('Y-m-d') === $date ? $date : null;
    }

    private function pageSize(int $pageDefault): int
    {
        $user = auth()->user();

        return app(UserPreferenceService::class)->pageSize($user instanceof User ? $user : null, $pageDefault);
    }

    private function authorizeInventoryWrite(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'inventory', write: true);

        return $user;
    }
}
