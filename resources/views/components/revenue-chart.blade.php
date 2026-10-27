@props([
    'buckets',
    'granularity' => 'day',
])

@php
    // Same validated categorical pair as the forecast chart (light surface).
    $invoicedColor = '#2a78d6';
    $collectedColor = '#eb6834';
    $width = 960;
    $height = 240;
    $paddingLeft = 56;
    $paddingRight = 8;
    $paddingTop = 10;
    $paddingBottom = 24;
    $plotWidth = $width - $paddingLeft - $paddingRight;
    $plotHeight = $height - $paddingTop - $paddingBottom;
    $baseline = $paddingTop + $plotHeight;
    $count = max(count($buckets), 1);
    $slot = $plotWidth / $count;
    $gap = 2;
    $barWidth = max(1, min(28, ($slot * 0.8 - $gap) / 2));
    $groupWidth = $barWidth * 2 + $gap;

    $rawMax = max(1.0, (float) collect($buckets)->flatMap(fn (array $bucket): array => [(float) $bucket['invoicedNet'], (float) $bucket['collected']])->max());
    $magnitude = 10 ** max(0, floor(log10($rawMax)));
    $yMax = ceil($rawMax * 1.1 / $magnitude) * $magnitude;
    $y = fn (float $value): float => round($baseline - ($value / $yMax) * $plotHeight, 2);
    $compact = fn (float $value): string => match (true) {
        $value >= 1_000_000 => rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.').'M',
        $value >= 1_000 => rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.').'k',
        default => number_format($value),
    };
    $money = fn (string $value): string => \App\Services\Orders\Money::format($value);

    // A bar with 4px rounded top corners and a square end on the baseline.
    $bar = function (float $x, float $value) use ($y, $baseline, $barWidth): ?string {
        if ($value <= 0) {
            return null;
        }

        $top = $y($value);
        $radius = min(4, $barWidth / 2, $baseline - $top);

        return sprintf(
            'M%1$.2F,%2$.2F V%3$.2F Q%1$.2F,%4$.2F %5$.2F,%4$.2F H%6$.2F Q%7$.2F,%4$.2F %7$.2F,%3$.2F V%2$.2F Z',
            $x, $baseline, $top + $radius, $top, $x + $radius, $x + $barWidth - $radius, $x + $barWidth,
        );
    };

    $labelEvery = max(1, (int) ceil($count / 8));
    $gridValues = [0, $yMax / 2, $yMax];
    $summary = 'Invoiced (net of VAT) and collected by '.$granularity.', '.count($buckets).' '.\Illuminate\Support\Str::plural($granularity, count($buckets)).'.';
@endphp

<figure {{ $attributes->class(['min-w-0']) }} x-data="{ active: null }">
    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2 text-[11px] text-gray-500">
        <div class="flex items-center gap-3" aria-hidden="true">
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background: {{ $invoicedColor }}"></span>Invoiced (net)</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background: {{ $collectedColor }}"></span>Collected</span>
        </div>
        <p class="tabular-nums text-gray-600" aria-live="polite">
            <template x-if="active === null"><span>Hover a {{ $granularity }} for its values</span></template>
            @foreach (array_values($buckets) as $index => $bucket)
                <template x-if="active === {{ $index }}">
                    <span><b class="font-semibold text-gray-800">{{ $bucket['label'] }}</b> · Invoiced {{ $money($bucket['invoicedNet']) }} · Collected {{ $money($bucket['collected']) }}</span>
                </template>
            @endforeach
        </p>
    </div>

    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="h-auto w-full overflow-visible" role="img" aria-labelledby="revenue-chart-title" x-on:mouseleave="active = null">
        <title id="revenue-chart-title">{{ $summary }}</title>

        @foreach ($gridValues as $gridValue)
            <line x1="{{ $paddingLeft }}" x2="{{ $width - $paddingRight }}" y1="{{ $y($gridValue) }}" y2="{{ $y($gridValue) }}" stroke="#e2e8f0" stroke-width="1" />
            <text x="{{ $paddingLeft - 6 }}" y="{{ $y($gridValue) + 3 }}" text-anchor="end" font-size="11" fill="#64748b">{{ $compact($gridValue) }}</text>
        @endforeach

        @foreach (array_values($buckets) as $index => $bucket)
            @php
                $groupX = $paddingLeft + $index * $slot + ($slot - $groupWidth) / 2;
            @endphp
            <rect x-show="active === {{ $index }}" style="display: none" x="{{ round($paddingLeft + $index * $slot, 2) }}" y="{{ $paddingTop }}" width="{{ round($slot, 2) }}" height="{{ $plotHeight }}" fill="#f1f5f9" />
            @if ($path = $bar($groupX, (float) $bucket['invoicedNet']))
                <path d="{{ $path }}" fill="{{ $invoicedColor }}" />
            @endif
            @if ($path = $bar($groupX + $barWidth + $gap, (float) $bucket['collected']))
                <path d="{{ $path }}" fill="{{ $collectedColor }}" />
            @endif
            @if ($index % $labelEvery === 0)
                <text x="{{ round($paddingLeft + ($index + 0.5) * $slot, 2) }}" y="{{ $height - 6 }}" text-anchor="middle" font-size="11" fill="#64748b">{{ \Illuminate\Support\Str::of($bucket['label'])->replace('Week of ', '')->before(' 20') }}</text>
            @endif
            <rect x="{{ round($paddingLeft + $index * $slot, 2) }}" y="0" width="{{ max(round($slot, 2), 4) }}" height="{{ $height }}" fill="transparent" x-on:mouseenter="active = {{ $index }}" />
        @endforeach

        <line x1="{{ $paddingLeft }}" x2="{{ $width - $paddingRight }}" y1="{{ $baseline }}" y2="{{ $baseline }}" stroke="#cbd5e1" stroke-width="1" />
    </svg>

    @if ($rawMax <= 1.0)
        <p class="mt-1 text-[11px] text-gray-500">Nothing was invoiced or collected in this period.</p>
    @endif

    <details class="mt-1.5 text-xs text-gray-600">
        <summary class="cursor-pointer select-none font-semibold text-blue-700 hover:text-blue-900">Show data table</summary>
        <table class="mt-2 w-full border-collapse text-left tabular-nums">
            <caption class="sr-only">{{ $summary }}</caption>
            <thead>
                <tr class="text-[10.5px] uppercase tracking-wide text-gray-500">
                    <th scope="col" class="py-1 pr-2 font-semibold">{{ ucfirst($granularity) }}</th>
                    <th scope="col" class="py-1 pr-2 text-right font-semibold">Invoiced (net)</th>
                    <th scope="col" class="py-1 pr-2 text-right font-semibold">Invoiced (gross)</th>
                    <th scope="col" class="py-1 text-right font-semibold">Collected</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($buckets as $bucket)
                    <tr class="border-t border-gray-100">
                        <td class="py-1 pr-2">{{ $bucket['label'] }}</td>
                        <td class="py-1 pr-2 text-right">{{ $money($bucket['invoicedNet']) }}</td>
                        <td class="py-1 pr-2 text-right">{{ $money($bucket['invoicedGross']) }}</td>
                        <td class="py-1 text-right">{{ $money($bucket['collected']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
</figure>
