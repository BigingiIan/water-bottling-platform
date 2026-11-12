@props(['profile', 'label', 'mode'])

{{--
    One colour profile in one mode, drawn with the real token classes. The tile sets its own token values,
    so every profile can be shown side by side whatever the page itself is using.
--}}
<figure
    data-preview-profile="{{ $profile }}"
    data-preview-mode="{{ $mode }}"
    style="{{ app(\App\Support\Appearance\Palette::class)->declarations($profile, $mode) }}"
    {{ $attributes->class(['min-w-0 overflow-hidden rounded-lg border border-border-strong bg-background text-fg']) }}
>
    <figcaption class="flex items-center justify-between gap-2 bg-sidebar px-3 py-2 text-xs font-semibold text-sidebar-strong">
        <span>{{ $label }}</span>
        <span class="text-sidebar-muted">{{ $mode === 'dark' ? 'Dark' : 'Light' }}</span>
    </figcaption>
    <div class="flex flex-col gap-2.5 p-3" aria-hidden="true">
        <div class="rounded-md border border-border bg-surface p-2.5">
            <p class="text-[12px] font-bold text-fg">Today's output</p>
            <p class="text-[11px] text-muted">3 lines running · 12,400 bottles</p>
            <div class="mt-2 flex items-center justify-between gap-2 border-t border-border pt-2 text-[11px]">
                <span class="font-semibold text-fg-soft">ORD-2026-0142</span>
                <span class="text-muted">Kisumu Fresh</span>
                <span class="font-semibold text-accent-ink">KES 18,500</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-1">
            <span class="rounded-full bg-success-soft px-2 py-0.5 text-[10.5px] font-bold text-success-ink">Delivered</span>
            <span class="rounded-full bg-warning-soft px-2 py-0.5 text-[10.5px] font-bold text-warning-ink">Low</span>
            <span class="rounded-full bg-danger-soft px-2 py-0.5 text-[10.5px] font-bold text-danger-ink">Overdue</span>
            <span class="rounded-full bg-info-soft px-2 py-0.5 text-[10.5px] font-bold text-info-ink">Scheduled</span>
        </div>
        <div class="flex gap-1.5">
            <span class="rounded-md bg-accent px-2.5 py-1 text-[11px] font-semibold text-on-accent">Save</span>
            <span class="rounded-md border border-border-strong bg-surface px-2.5 py-1 text-[11px] font-semibold text-fg-soft">Cancel</span>
            <span class="rounded-md bg-danger px-2.5 py-1 text-[11px] font-semibold text-on-danger">Delete</span>
        </div>
        <svg viewBox="0 0 120 36" class="h-9 w-full rounded-md bg-surface">
            <line x1="4" x2="116" y1="32" y2="32" class="stroke-border-strong" stroke-width="1" />
            @foreach ([[10, 18], [38, 10], [66, 22], [94, 14]] as $index => [$x, $top])
                <rect x="{{ $x }}" y="{{ $top }}" width="16" height="{{ 32 - $top }}" rx="1.5" @class(['fill-chart-1' => $index === 0, 'fill-chart-2' => $index === 1, 'fill-chart-3' => $index === 2, 'fill-chart-4' => $index === 3]) />
            @endforeach
        </svg>
    </div>
</figure>
