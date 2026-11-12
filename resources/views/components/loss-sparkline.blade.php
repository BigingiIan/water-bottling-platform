@props([
    'values',
    'label',
    'normal',
    'review',
])

@php
    // Single series (no legend needed). Values are loss beyond normal, so 0 is the normal loss and the
    // dashed line is the review threshold.
    $width = 180;
    $height = 44;
    $padding = 4;
    $count = count($values);
    $max = max([...$values, $review, 1.0]) * 1.15;
    $min = min([...$values, 0.0]);
    $span = max($max - $min, 0.001);
    $x = fn (int $index): float => round($padding + ($count > 1 ? $index * ($width - 2 * $padding) / ($count - 1) : ($width - 2 * $padding) / 2), 2);
    $y = fn (float $value): float => round($padding + ($height - 2 * $padding) * (1 - ($value - $min) / $span), 2);
    $points = collect($values)->map(fn (float $value, int $index): string => $x($index).','.$y($value))->implode(' ');
    $latest = $count > 0 ? end($values) : null;
    $summary = $count === 0
        ? "{$label}: no completed batches yet."
        : sprintf('%s: loss beyond the %.1f%% normal loss over the last %d batches, latest %.1f%%, review at %.1f%%.', $label, $normal, $count, $latest, $review);
@endphp

<svg viewBox="0 0 {{ $width }} {{ $height }}" {{ $attributes->class(['h-11 w-full']) }} role="img" aria-label="{{ $summary }}">
    <title>{{ $summary }}</title>
    <line x1="{{ $padding }}" x2="{{ $width - $padding }}" y1="{{ $y(0.0) }}" y2="{{ $y(0.0) }}" class="stroke-subtle" stroke-width="1" />
    <line x1="{{ $padding }}" x2="{{ $width - $padding }}" y1="{{ $y($review) }}" y2="{{ $y($review) }}" class="stroke-warning" stroke-width="1" stroke-dasharray="3 3" />
    @if ($count > 1)
        <polyline points="{{ $points }}" fill="none" class="stroke-chart-1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
    @endif
    @if ($latest !== null)
        <circle cx="{{ $x($count - 1) }}" cy="{{ $y($latest) }}" r="3.5" stroke-width="2" class="fill-chart-1 stroke-surface" />
    @endif
</svg>
