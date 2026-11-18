@php
    $coverWarningDays = (float) app(\App\Services\SettingsService::class)->get('inventory.cover_warning_days', 1.0);
    $emptyClasses = 'flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-muted';
@endphp
<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Tomorrow's forecast vs stock cover" icon="presentation-chart-line" :href="route('forecasting.index')" link-label="Forecasting">
        @if (! $forecastCover['enabled'])
            <p class="{{ $emptyClasses }}">ML suggestions are turned off in Settings.</p>
        @elseif ($forecastCover['rows']->isEmpty())
            <p class="{{ $emptyClasses }}">No products to forecast yet.</p>
        @elseif ($forecastCover['rows']->every(fn (array $row): bool => $row['forecast'] === null))
            <div class="{{ $emptyClasses }}">
                <p>No forecasts for tomorrow yet.</p>
                <a href="{{ route('forecasting.index') }}" class="text-xs font-semibold text-accent-ink hover:text-accent-ink">See why on the Forecasting page</a>
            </div>
        @else
            <div class="relative -mx-[18px] -my-4 overflow-x-auto">
                <table class="w-full min-w-[480px] border-collapse text-left">
                    <caption class="sr-only">Tomorrow's forecast demand, current stock, and days of cover per SKU.</caption>
                    <thead class="bg-surface-muted">
                        <tr>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">SKU</th>
                            <th scope="col" class="px-[18px] py-2.5 text-right text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Forecast</th>
                            <th scope="col" class="px-[18px] py-2.5 text-right text-[11px] font-bold uppercase tracking-[0.4px] text-muted">In stock</th>
                            <th scope="col" class="px-[18px] py-2.5 text-right text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Cover</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($forecastCover['rows'] as $row)
                            @php
                                $lowCover = $row['coverDays'] !== null && $row['coverDays'] < $coverWarningDays;
                            @endphp
                            <tr wire:key="dashboard-cover-{{ $row['product']->id }}" class="border-t border-border text-[13px]">
                                <td class="px-[18px] py-2.5"><span class="whitespace-nowrap rounded bg-tag-soft px-2.5 py-[3px] text-[11px] font-bold text-tag-ink">{{ $row['product']->unit_type }}</span></td>
                                <td class="px-[18px] py-2.5 text-right tabular-nums text-fg">{{ $row['forecast'] === null ? 'No forecast' : number_format($row['forecast']) }}</td>
                                <td class="px-[18px] py-2.5 text-right tabular-nums text-fg">{{ number_format($row['units']) }}</td>
                                <td @class(['px-[18px] py-2.5 text-right tabular-nums', 'font-semibold text-warning-ink' => $lowCover, 'text-fg' => ! $lowCover])>
                                    @if ($row['coverDays'] === null)
                                        —
                                    @else
                                        <span class="inline-flex items-center gap-1">
                                            @if ($lowCover)<x-heroicon-o-exclamation-triangle class="size-4" aria-hidden="true" /><span class="sr-only">Low cover:</span>@endif
                                            {{ \App\Support\Plural::count(round($row['coverDays'], 1), 'day') }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-dashboard-card>
</div>
