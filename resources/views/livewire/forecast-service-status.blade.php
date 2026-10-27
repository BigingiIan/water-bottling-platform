<span class="inline-flex items-center">
    @if ($isVisible)
        @php($statusLabel = $isReachable ? 'Forecast service reachable' : 'Forecast service unreachable')
        <span
            tabindex="0"
            title="{{ $statusLabel }}"
            class="group relative inline-flex items-center gap-1.5 whitespace-nowrap rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
        >
            <span aria-hidden="true" @class([
                'size-2 rounded-full',
                'bg-green-500' => $isReachable,
                'bg-red-500' => ! $isReachable,
            ])></span>
            <span aria-hidden="true">ML</span>
            <span class="sr-only">{{ $statusLabel }}</span>
            <span aria-hidden="true" class="pointer-events-none absolute bottom-full right-0 mb-2 hidden whitespace-nowrap rounded-md bg-navy-950 px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block group-focus-visible:block">
                {{ $statusLabel }}
            </span>
        </span>
    @endif
</span>
