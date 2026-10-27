@props([
    'key',
    'icon' => 'inbox',
    'filtered' => false,
    'can' => false,
    'actionHref' => null,
    'actionClick' => null,
])

{{-- Text comes from lang/en/help.php ("empty.<key>"); the action shows only when the caller says the user may perform it. --}}
@php
    $actionLabel = trans()->has("help.empty.{$key}.action") ? __("help.empty.{$key}.action") : null;
    $showAction = ! $filtered && $can && $actionLabel !== null && ($actionHref !== null || $actionClick !== null);
@endphp

<div {{ $attributes->class(['flex flex-col items-center px-[18px] py-10 text-center']) }}>
    <x-dynamic-component :component="'heroicon-o-'.$icon" class="size-8 text-gray-300" aria-hidden="true" />
    <p class="mt-2 text-sm font-semibold text-gray-700">{{ __("help.empty.{$key}.title") }}</p>
    <p class="mt-1 max-w-sm text-xs text-gray-500">{{ $filtered ? __('help.empty.filtered') : __("help.empty.{$key}.body") }}</p>
    @if ($showAction)
        @if ($actionHref !== null)
            <a href="{{ $actionHref }}" class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                <x-heroicon-o-plus class="size-3.5" aria-hidden="true" />
                {{ $actionLabel }}
            </a>
        @else
            <button type="button" wire:click="{{ $actionClick }}" class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                <x-heroicon-o-plus class="size-3.5" aria-hidden="true" />
                {{ $actionLabel }}
            </button>
        @endif
    @endif
</div>
