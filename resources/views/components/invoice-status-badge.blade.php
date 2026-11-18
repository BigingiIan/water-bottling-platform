@props(['invoice'])

@php
    $overdue = $invoice->isOverdue();
    $classes = $overdue ? 'bg-danger-soft text-danger-ink' : $invoice->status->badgeClasses();
    $dot = $overdue ? 'bg-danger' : $invoice->status->dotClasses();
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-[5px] whitespace-nowrap rounded-full px-[9px] py-[3px] text-[11px] font-bold', $classes]) }}>
    <span aria-hidden="true" class="size-1.5 rounded-full {{ $dot }}"></span>{{ $overdue ? 'Overdue' : $invoice->status->label() }}
</span>
