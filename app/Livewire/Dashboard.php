<?php

namespace App\Livewire;

use App\Models\LeakageReport;
use App\Models\Product;
use App\Models\ProductionSchedule;
use App\Models\User;
use App\Services\Forecasting\ForecastOverview;
use App\Services\Inventory\StockService;
use App\Services\SettingsService;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Dashboard', 'breadcrumbSection' => 'Home', 'activeItem' => 'Dashboard'])]
class Dashboard extends Component
{
    private const LATEST_NOTIFICATIONS = 5;

    public function render(ModuleAccess $access): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        $access->authorize($user, 'dashboard');

        $canViewProduction = $access->can($user, 'production');
        $canViewInventory = $access->can($user, 'inventory');
        $canViewForecasting = $access->can($user, 'forecasting');

        return view('livewire.dashboard', [
            'user' => $user,
            'production' => $canViewProduction ? $this->productionSummary() : null,
            'canCreateSchedule' => $access->can($user, 'production', write: true),
            'stockAlerts' => $canViewInventory ? $this->stockAlerts() : null,
            'leakageAttentionCount' => $canViewInventory ? $this->leakageAttentionCount() : null,
            'forecastCover' => $canViewForecasting ? $this->forecastCover() : null,
            'notifications' => $user->notifications()->latest()->limit(self::LATEST_NOTIFICATIONS)->get(),
        ]);
    }

    /**
     * @return array{target: int, actual: int, percent: int, scheduledRuns: int, openRuns: Collection<int, ProductionSchedule>}
     */
    private function productionSummary(): array
    {
        $schedules = ProductionSchedule::query()
            ->with('productionLine:id,name')
            ->withSum('logs', 'units_logged')
            ->whereDate('scheduled_date', today()->toDateString())
            ->orderBy('scheduled_time')
            ->get();
        $target = (int) $schedules->sum('target_units');
        $actual = (int) $schedules->sum('logs_sum_units_logged');

        return [
            'target' => $target,
            'actual' => $actual,
            'percent' => $target > 0 ? (int) round($actual / $target * 100) : 0,
            'scheduledRuns' => $schedules->count(),
            'openRuns' => $schedules->whereIn('status', ['active', 'queued'])->values(),
        ];
    }

    /**
     * @return Collection<int, array{product: Product, status: string, units: float}>
     */
    private function stockAlerts(): Collection
    {
        $stockService = app(StockService::class);
        $totals = DB::table('warehouse_stock')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(current_level) as total_units')
            ->pluck('total_units', 'product_id');

        return Product::query()
            ->orderBy('unit_type')
            ->get(['id', 'name', 'unit_type', 'reorder_threshold'])
            ->map(fn (Product $product): array => [
                'product' => $product,
                'units' => $units = (float) ($totals[$product->id] ?? 0),
                'status' => $stockService->statusForProduct($product, $units),
            ])
            ->filter(fn (array $alert): bool => $alert['status'] !== 'ok')
            ->sortBy(fn (array $alert): int => match ($alert['status']) {
                'out' => 0,
                'critical' => 1,
                default => 2,
            })
            ->values();
    }

    /**
     * Open leakage reports graded review or investigate, from any source.
     */
    private function leakageAttentionCount(): int
    {
        return LeakageReport::query()->where('status', 'open')->whereNotNull('severity')->count();
    }

    /**
     * @return array{enabled: bool, rows: Collection<int, array{product: Product, forecast: ?float, units: float, coverDays: ?float}>}
     */
    private function forecastCover(): array
    {
        if (! app(SettingsService::class)->get('forecast.enabled', true)) {
            return ['enabled' => false, 'rows' => collect()];
        }

        return ['enabled' => true, 'rows' => app(ForecastOverview::class)->tomorrowCover()];
    }
}
