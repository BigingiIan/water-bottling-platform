@props(['title', 'subtitle' => null, 'headingId' => null])

<header {{ $attributes->class(['flex flex-wrap items-end justify-between gap-3']) }}>
    <div class="min-w-0">
        <h1 @if ($headingId) id="{{ $headingId }}" @endif class="text-[21px] font-extrabold tracking-tight text-gray-900">{{ $title }}</h1>
        @if (filled($subtitle))
            <p class="mt-1 text-[13px] text-gray-500">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</header>
