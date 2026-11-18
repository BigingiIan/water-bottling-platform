@props(['status'])

@php
    [$label, $badgeClass, $dotClass] = match ($status) {
        'out' => ['Out of stock', 'bg-danger-soft text-danger-ink', 'bg-danger'],
        'critical' => ['Critical', 'bg-danger-soft text-danger-ink', 'bg-danger'],
        'low' => ['Low', 'bg-warning-soft text-warning-ink', 'bg-warning'],
        default => ['OK', 'bg-success-soft text-success-ink', 'bg-success'],
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-[5px] whitespace-nowrap rounded-full px-[9px] py-[3px] text-[11px] font-bold', $badgeClass]) }}>
    <span aria-hidden="true" class="size-1.5 rounded-full {{ $dotClass }}"></span>{{ $label }}
</span>
