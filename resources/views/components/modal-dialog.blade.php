@props([
    'id',
    'title',
    'close',
    'maxWidth' => 'max-w-[460px]',
])

<div wire:click.self="{{ $close }}" x-data x-on:keydown.escape.window="$wire.{{ $close }}()" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/45 p-4">
    <section role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-heading" @class(['w-full overflow-hidden rounded-[10px] bg-white shadow-xl', $maxWidth])>
        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
            <h2 id="{{ $id }}-heading" class="text-[15px] font-bold text-gray-900">{{ $title }}</h2>
            <button type="button" wire:click="{{ $close }}" aria-label="Close {{ strtolower($title) }} dialog" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                <x-heroicon-o-x-mark class="size-5" aria-hidden="true" />
            </button>
        </div>
        <div class="flex flex-col gap-3.5 px-5 py-[18px]">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3.5">
                {{ $footer }}
            </div>
        @endisset
    </section>
</div>
