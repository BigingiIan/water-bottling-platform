@props(['status'])

@php
    [$label, $badgeClass, $dotClass] = match ($status) {
        'out' => ['Out of stock', 'bg-red-100 text-[#991b1b]', 'bg-red-600'],
        'critical' => ['Critical', 'bg-red-50 text-[#b91c1c]', 'bg-red-500'],
        'low' => ['Low', 'bg-amber-50 text-[#b45309]', 'bg-amber-500'],
        default => ['OK', 'bg-green-50 text-[#15803d]', 'bg-green-500'],
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-[5px] whitespace-nowrap rounded-full px-[9px] py-[3px] text-[11px] font-bold', $badgeClass]) }}>
    <span aria-hidden="true" class="size-1.5 rounded-full {{ $dotClass }}"></span>{{ $label }}
</span>
