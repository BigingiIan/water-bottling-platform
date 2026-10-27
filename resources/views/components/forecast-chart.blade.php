@props([
    'points',
    'label',
])

@php
    // Two categorical series validated with the dataviz palette checks (light surface).
    $actualColor = '#2a78d6';
    $forecastColor = '#eb6834';
    $width = 560;
    $height = 170;
    $paddingLeft = 40;
    $paddingRight = 10;
    $paddingTop = 10;
    $paddingBottom = 22;
    $plotWidth = $width - $paddingLeft - $paddingRight;
    $plotHeight = $height - $paddingTop - $paddingBottom;
    $count = max(count($points), 1);
    $step = $count > 1 ? $plotWidth / ($count - 1) : 0;

    $values = collect($points)->flatMap(fn (array $point): array => [$point['actual'], $point['forecast']])->filter(fn ($value) => $value !== null);
    $rawMax = max((float) $values->max(), 1.0);
    $magnitude = 10 ** max(0, floor(log10($rawMax)));
    $yMax = ceil($rawMax * 1.1 / $magnitude) * $magnitude;

    $x = fn (int $index): float => round($paddingLeft + $index * $step, 2);
    $y = fn (float $value): float => round($paddingTop + $plotHeight - ($value / $yMax) * $plotHeight, 2);

    $segments = function (string $key) use ($points, $x, $y): array {
        $segments = [];
        $current = [];

        foreach (array_values($points) as $index => $point) {
            if ($point[$key] === null) {
                if (count($current) > 0) {
                    $segments[] = $current;
                }
                $current = [];

                continue;
            }

            $current[] = $x($index).','.$y((float) $point[$key]);
        }

        if (count($current) > 0) {
            $segments[] = $current;
        }

        return $segments;
    };

    $actualSegments = $segments('actual');
    $forecastSegments = $segments('forecast');
    $hasForecast = $forecastSegments !== [];
    $lastActualIndex = collect($points)->keys()->filter(fn ($index) => $points[$index]['actual'] !== null)->last();
    $lastForecastIndex = collect($points)->keys()->filter(fn ($index) => $points[$index]['forecast'] !== null)->last();
    $gridValues = [0, $yMax / 2, $yMax];
    $formatValue = fn (?float $value): string => $value === null ? '—' : number_format($value);
    $chartId = 'forecast-chart-'.\Illuminate\Support\Str::slug($label);
    $summary = $label.': actual sales vs forecast for the last '.count($points).' days.';
@endphp

<figure {{ $attributes->class(['min-w-0']) }} x-data="{ active: null }">
    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2 text-[11px] text-gray-500">
        <div class="flex items-center gap-3" aria-hidden="true">
            <span class="inline-flex items-center gap-1.5">
                <svg width="16" height="6" viewBox="0 0 16 6"><line x1="1" y1="3" x2="15" y2="3" stroke="{{ $actualColor }}" stroke-width="2" stroke-linecap="round" /></svg>
                Actual sales
            </span>
            <span class="inline-flex items-center gap-1.5">
                <svg width="16" height="6" viewBox="0 0 16 6"><line x1="1" y1="3" x2="15" y2="3" stroke="{{ $forecastColor }}" stroke-width="2" stroke-dasharray="4 3" stroke-linecap="round" /></svg>
                Forecast
            </span>
        </div>
        <p class="tabular-nums text-gray-600" aria-live="polite">
            <template x-if="active === null"><span>Hover the chart for daily values</span></template>
            @foreach (array_values($points) as $index => $point)
                <template x-if="active === {{ $index }}">
                    <span><b class="font-semibold text-gray-800">{{ $point['label'] }}</b> · Actual {{ $formatValue($point['actual']) }} · Forecast {{ $formatValue($point['forecast']) }}</span>
                </template>
            @endforeach
        </p>
    </div>

    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="h-auto w-full overflow-visible" role="img" aria-labelledby="{{ $chartId }}-title" x-on:mouseleave="active = null">
        <title id="{{ $chartId }}-title">{{ $summary }}</title>

        @foreach ($gridValues as $gridValue)
            <line x1="{{ $paddingLeft }}" x2="{{ $width - $paddingRight }}" y1="{{ $y($gridValue) }}" y2="{{ $y($gridValue) }}" stroke="#e2e8f0" stroke-width="1" />
            <text x="{{ $paddingLeft - 6 }}" y="{{ $y($gridValue) + 3 }}" text-anchor="end" font-size="11" fill="#64748b">{{ number_format($gridValue) }}</text>
        @endforeach

        @if (count($points) > 0)
            <text x="{{ $x(0) }}" y="{{ $height - 6 }}" text-anchor="start" font-size="11" fill="#64748b">{{ $points[array_key_first($points)]['label'] }}</text>
            <text x="{{ $x(count($points) - 1) }}" y="{{ $height - 6 }}" text-anchor="end" font-size="11" fill="#64748b">{{ $points[array_key_last($points)]['label'] }}</text>
        @endif

        @foreach (array_values($points) as $index => $point)
            <line x-show="active === {{ $index }}" style="display: none" x1="{{ $x($index) }}" x2="{{ $x($index) }}" y1="{{ $paddingTop }}" y2="{{ $paddingTop + $plotHeight }}" stroke="#94a3b8" stroke-width="1" />
        @endforeach

        @foreach ($actualSegments as $segment)
            <polyline points="{{ implode(' ', $segment) }}" fill="none" stroke="{{ $actualColor }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
        @endforeach
        @foreach ($forecastSegments as $segment)
            <polyline points="{{ implode(' ', $segment) }}" fill="none" stroke="{{ $forecastColor }}" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round" stroke-linecap="round" />
        @endforeach

        @if ($lastActualIndex !== null)
            <circle cx="{{ $x($lastActualIndex) }}" cy="{{ $y((float) $points[$lastActualIndex]['actual']) }}" r="4" fill="{{ $actualColor }}" stroke="#ffffff" stroke-width="2" />
        @endif
        @if ($lastForecastIndex !== null)
            <circle cx="{{ $x($lastForecastIndex) }}" cy="{{ $y((float) $points[$lastForecastIndex]['forecast']) }}" r="4" fill="{{ $forecastColor }}" stroke="#ffffff" stroke-width="2" />
        @endif

        @foreach (array_values($points) as $index => $point)
            @foreach (['actual' => $actualColor, 'forecast' => $forecastColor] as $series => $color)
                @if ($point[$series] !== null)
                    <circle x-show="active === {{ $index }}" style="display: none" cx="{{ $x($index) }}" cy="{{ $y((float) $point[$series]) }}" r="4" fill="{{ $color }}" stroke="#ffffff" stroke-width="2" />
                @endif
            @endforeach
            <rect x="{{ max(0, $x($index) - $step / 2) }}" y="0" width="{{ max($step, 8) }}" height="{{ $height }}" fill="transparent" x-on:mouseenter="active = {{ $index }}" />
        @endforeach
    </svg>

    @if (! $hasForecast || $values->sum() == 0)
        <p class="mt-1 text-[11px] text-gray-500">
            {{ $values->sum() == 0 ? 'No sales were recorded in this window.' : '' }}
            {{ $hasForecast ? '' : 'No stored forecasts in this window yet.' }}
        </p>
    @endif

    <details class="mt-1.5 text-xs text-gray-600">
        <summary class="cursor-pointer select-none font-semibold text-blue-700 hover:text-blue-900">Show data table</summary>
        <table class="mt-2 w-full border-collapse text-left tabular-nums">
            <caption class="sr-only">{{ $summary }}</caption>
            <thead>
                <tr class="text-[10.5px] uppercase tracking-wide text-gray-500">
                    <th scope="col" class="py-1 pr-2 font-semibold">Date</th>
                    <th scope="col" class="py-1 pr-2 text-right font-semibold">Actual</th>
                    <th scope="col" class="py-1 text-right font-semibold">Forecast</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($points as $point)
                    <tr class="border-t border-gray-100">
                        <td class="py-1 pr-2">{{ $point['label'] }}</td>
                        <td class="py-1 pr-2 text-right">{{ $formatValue($point['actual']) }}</td>
                        <td class="py-1 text-right">{{ $formatValue($point['forecast']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
</figure>
