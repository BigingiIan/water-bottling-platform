<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Revenue\RevenuePeriod;
use App\Services\Revenue\RevenueReport;
use App\Services\SettingsService;
use App\Support\ModuleAccess;
use App\Support\SettingsDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('components.app-layout', ['title' => 'Revenue', 'breadcrumbSection' => 'Finance', 'activeItem' => 'Revenue'])]
class RevenuePage extends Component
{
    public const GRANULARITIES = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month'];

    #[Url(except: 'this_month')]
    public string $period = 'this_month';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: 'day')]
    public string $granularity = 'day';

    public function mount(): void
    {
        app(ModuleAccess::class)->authorize($this->user(), 'revenue');
    }

    public function updatedPeriod(): void
    {
        if ($this->period === 'custom' && ($this->from === '' || $this->to === '')) {
            $this->from = CarbonImmutable::today()->startOfMonth()->toDateString();
            $this->to = CarbonImmutable::today()->toDateString();
        }
    }

    public function exportCsv(RevenueReport $report): StreamedResponse
    {
        app(ModuleAccess::class)->authorize($this->user(), 'revenue');
        [$period] = $this->resolvePeriod();
        $granularity = $this->resolvedGranularity();
        $kpis = $report->kpis($period);
        $series = $report->series($period, $granularity);
        $products = $report->byProduct($period);
        $customers = $report->topCustomers($period);
        $aging = $report->aging($period->today);
        $forecast = $report->forecastEstimate($period->today);
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        return response()->streamDownload(function () use ($period, $kpis, $series, $products, $customers, $aging, $forecast): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['section', 'label', 'metric', 'value']);
            fputcsv($output, ['period', $period->from->toDateString().' to '.$period->to->toDateString(), 'compared_with', $period->previousFrom->toDateString().' to '.$period->previousTo->toDateString()]);

            foreach ($kpis as $name => $kpi) {
                fputcsv($output, ['kpi', $name, 'current', $kpi['current']]);
                fputcsv($output, ['kpi', $name, 'previous', $kpi['previous']]);
                fputcsv($output, ['kpi', $name, 'change_pct', $kpi['change'] ?? '']);
            }

            foreach ($series as $bucket) {
                fputcsv($output, ['series', $bucket['key'], 'invoiced_net', $bucket['invoicedNet']]);
                fputcsv($output, ['series', $bucket['key'], 'invoiced_gross', $bucket['invoicedGross']]);
                fputcsv($output, ['series', $bucket['key'], 'collected', $bucket['collected']]);
            }

            foreach ($products['rows'] as $row) {
                $label = $row['sku'] === null ? $row['name'] : "{$row['name']} ({$row['sku']})";
                fputcsv($output, ['product', $label, 'units', $row['units']]);
                fputcsv($output, ['product', $label, 'net', $row['net']]);
                fputcsv($output, ['product', $label, 'share_pct', $row['share']]);
            }

            foreach ($customers as $row) {
                fputcsv($output, ['customer', $row['name'], 'invoiced_net', $row['net']]);
                fputcsv($output, ['customer', $row['name'], 'invoiced_gross', $row['gross']]);
                fputcsv($output, ['customer', $row['name'], 'outstanding', $row['outstanding']]);
            }

            foreach ([...$aging['rows'], ['name' => 'Total', ...$aging['totals']]] as $row) {
                foreach ([...array_keys(RevenueReport::AGING_BUCKETS), 'total'] as $bucket) {
                    fputcsv($output, ['aging', $row['name'], $bucket, $row[$bucket]]);
                }
            }

            foreach ($forecast['rows'] as $row) {
                $label = "{$row['name']} ({$row['sku']})";

                foreach ($row['daily'] as $date => $units) {
                    fputcsv($output, ['forecast_estimate', $label, "units_{$date}", $units ?? 'no forecast']);
                }

                fputcsv($output, ['forecast_estimate', $label, 'unit_price', $row['unitPrice']]);
                fputcsv($output, ['forecast_estimate', $label, 'expected_revenue', $row['coveredDays'] === 0 ? 'no forecast' : $row['revenue']]);
            }

            fputcsv($output, ['forecast_estimate', 'Total', 'expected_revenue', $forecast['totalRevenue']]);
            fclose($output);
        }, "revenue-{$from}_{$to}.csv", ['Content-Type' => 'text/csv']);
    }

    public function render(RevenueReport $report, SettingsDate $dates, SettingsService $settings): View
    {
        app(ModuleAccess::class)->authorize($this->user(), 'revenue');
        [$period, $periodError] = $this->resolvePeriod();

        $this->resetErrorBag('to');

        if ($periodError !== null) {
            $this->addError('to', $periodError);
        }

        $granularity = $this->resolvedGranularity();

        return view('livewire.revenue-page', [
            'resolvedPeriod' => $period,
            'periodLabel' => $dates->formatDate($period->from).' – '.$dates->formatDate($period->to),
            'previousLabel' => $dates->formatDate($period->previousFrom).' – '.$dates->formatDate($period->previousTo),
            'asAtLabel' => $dates->formatDate($period->asAt()),
            'resolvedGranularity' => $granularity,
            'kpis' => $report->kpis($period),
            'series' => $report->series($period, $granularity),
            'products' => $report->byProduct($period),
            'customers' => $report->topCustomers($period),
            'aging' => $report->aging($period->today),
            'forecast' => $report->forecastEstimate($period->today),
            'pricesIncludeVat' => (bool) $settings->get('invoice.prices_include_vat', false),
        ]);
    }

    /**
     * The period from the URL state. An invalid custom range falls back to this month, with the reason.
     *
     * @return array{RevenuePeriod, ?string}
     */
    private function resolvePeriod(): array
    {
        $today = CarbonImmutable::today();
        $preset = array_key_exists($this->period, RevenuePeriod::PRESETS) ? $this->period : 'this_month';

        try {
            return [RevenuePeriod::fromInput($preset, $this->from, $this->to, $today), null];
        } catch (InvalidArgumentException $exception) {
            return [RevenuePeriod::fromInput('this_month', null, null, $today), $exception->getMessage().' Showing this month instead.'];
        }
    }

    private function resolvedGranularity(): string
    {
        return array_key_exists($this->granularity, self::GRANULARITIES) ? $this->granularity : 'day';
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
