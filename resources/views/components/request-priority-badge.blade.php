@props(['priority'])

<span {{ $attributes->class(['inline-flex items-center gap-1 whitespace-nowrap rounded px-2 py-[3px] text-[11px] font-bold', $priority->badgeClasses()]) }}>
    <x-dynamic-component :component="'heroicon-o-'.$priority->icon()" class="size-3" aria-hidden="true" />{{ $priority->label() }}
</span>
