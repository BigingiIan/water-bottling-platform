@props([
    'reduced' => false,
    'showForecastStatus' => false,
])

@php
    $footerDetails = app(\App\Support\FooterDetails::class);
    $helpUrl = $reduced ? null : $footerDetails->helpUrl();
@endphp

<footer {{ $attributes->class(['border-t border-gray-200 px-6 py-3 text-xs text-gray-500 sm:px-7 print:hidden']) }}>
    <div class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
        <p class="truncate">
            <span class="font-semibold text-gray-700">{{ $footerDetails->organisationName() }}</span>
            <span aria-hidden="true" class="mx-1 text-gray-300">·</span>
            <span>&copy; {{ $footerDetails->year() }}</span>
        </p>
        <div class="flex items-center gap-3 whitespace-nowrap">
            <span>AquaOps <span class="tabular-nums">v{{ $footerDetails->version() }}</span></span>
            @if ($helpUrl)
                <a href="{{ $helpUrl }}" class="font-semibold text-blue-700 hover:text-blue-900 hover:underline">Help</a>
            @endif
            @if (! $reduced && $showForecastStatus)
                <livewire:forecast-service-status />
            @endif
        </div>
    </div>
</footer>
