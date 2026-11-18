@props(['status'])

<span {{ $attributes->class(['inline-flex items-center gap-[5px] whitespace-nowrap rounded-full px-[9px] py-[3px] text-[11px] font-bold', \App\Models\Equipment::statusClasses($status)]) }}>
    <span aria-hidden="true" class="size-1.5 rounded-full {{ \App\Models\Equipment::statusDotClasses($status) }}"></span>{{ __('maintenance.equipment_statuses.'.$status) }}
</span>
