@props([
    'relatedType',
    'relatedId',
    'label' => 'Send request',
    'compact' => false,
])

<button
    type="button"
    wire:click="$dispatch('open-request-modal', { relatedType: '{{ $relatedType }}', relatedId: {{ (int) $relatedId }} })"
    @if ($compact) aria-label="{{ $label }}" title="{{ $label }}" @endif
    {{ $attributes->class([
        'inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface font-semibold text-fg-soft hover:bg-surface-muted',
        'px-3.5 py-2 text-[13px]' => ! $compact,
        'size-7 justify-center text-xs' => $compact,
    ]) }}
>
    <x-heroicon-o-paper-airplane class="size-4" aria-hidden="true" />
    @unless ($compact){{ $label }}@endunless
</button>
