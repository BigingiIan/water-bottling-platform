{{-- Each production line's equipment status and recent downtime, for the Production schedule. --}}
@php
    $lineStatuses = app(\App\Services\Maintenance\LineStatus::class)->all();
    $viewer = auth()->user();
    $canOpenEquipment = $viewer instanceof \App\Models\User && app(\App\Support\ModuleAccess::class)->can($viewer, 'maintenance');
@endphp

@if ($lineStatuses->isNotEmpty())
    <section aria-labelledby="line-status-heading" {{ $attributes->class(['rounded-lg border border-border bg-surface']) }}>
        <h2 id="line-status-heading" class="border-b border-border px-[18px] py-3 text-sm font-bold text-fg">{{ __('maintenance.line_status.heading') }}</h2>
        <ul class="grid gap-px bg-surface-strong sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($lineStatuses as $summary)
                <li wire:key="line-status-{{ $summary['line']->id }}" class="bg-surface px-[18px] py-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-[13px] font-semibold text-fg">{{ $summary['line']->name }}</span>
                        @if ($summary['status'])
                            <x-equipment-status-badge :status="$summary['status']" />
                        @else
                            <span class="text-[11px] text-muted">{{ __('maintenance.line_status.no_equipment') }}</span>
                        @endif
                    </div>
                    <p class="mt-1 text-[11.5px] text-muted">{{ __('maintenance.line_status.downtime', ['minutes' => number_format($summary['downtimeMinutes'])]) }}</p>
                    @foreach ($summary['disruptive'] as $equipment)
                        <p class="mt-1 truncate text-[11.5px] font-semibold text-danger-ink">
                            @if ($canOpenEquipment)
                                <a href="{{ route('maintenance.equipment.show', $equipment) }}" class="hover:underline">{{ $equipment->code }} {{ $equipment->name }}</a>
                            @else
                                {{ $equipment->code }} {{ $equipment->name }}
                            @endif
                            · {{ $equipment->statusLabel() }}
                        </p>
                    @endforeach
                </li>
            @endforeach
        </ul>
    </section>
@endif
