@props(['status'])

<span {{ $attributes->class(['inline-flex items-center gap-[5px] whitespace-nowrap rounded-full px-[9px] py-[3px] text-[11px] font-bold', $status->badgeClasses()]) }}>
    <span aria-hidden="true" class="size-1.5 rounded-full {{ $status->dotClasses() }}"></span>{{ $status->label() }}
</span>
