@props([
    'title',
    'href',
    'linkLabel',
    'icon',
])

<section {{ $attributes->class(['flex min-w-0 flex-col rounded-lg border border-border bg-surface shadow-sm']) }} aria-label="{{ $title }}">
    <header class="flex items-center justify-between gap-3 border-b border-border px-[18px] py-3.5">
        <h2 class="flex items-center gap-2 text-[13px] font-bold text-fg">
            <x-dynamic-component :component="'heroicon-o-'.$icon" class="size-5 text-muted" aria-hidden="true" />
            {{ $title }}
        </h2>
        <a href="{{ $href }}" class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-accent-ink hover:text-accent-ink">
            {{ $linkLabel }}
            <x-heroicon-o-arrow-right class="size-3.5" aria-hidden="true" />
        </a>
    </header>
    <div class="flex flex-1 flex-col px-[18px] py-4">
        {{ $slot }}
    </div>
</section>
