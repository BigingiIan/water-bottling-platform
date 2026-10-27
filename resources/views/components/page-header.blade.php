@props(['title', 'subtitle' => null, 'headingId' => null, 'help' => null])

@php
    // The "?" popover only renders for a page whose guide the user may read, so it never describes a module they can't open.
    $helpPage = $help === null ? null : trans("help.popover.pages.{$help}");
    $helpUser = auth()->user();
    $showHelp = is_array($helpPage)
        && $helpUser instanceof \App\Models\User
        && app(\App\Services\Help\HelpGuides::class)->canRead($helpUser, $helpPage['guide']);
    $helpPopoverId = 'page-help-'.$help;
@endphp

<header {{ $attributes->class(['flex flex-wrap items-end justify-between gap-3']) }}>
    <div class="min-w-0">
        <h1 @if ($headingId) id="{{ $headingId }}" @endif class="text-[21px] font-extrabold tracking-tight text-gray-900">{{ $title }}@if ($showHelp)<span
                x-data="{ open: false }"
                x-on:keydown.escape.window="if (open) { open = false; $refs.helpButton.focus() }"
                x-on:click.outside="open = false"
                class="relative ml-2 inline-flex align-[3px]"
            ><button
                    type="button"
                    x-ref="helpButton"
                    x-on:click="open = ! open"
                    aria-controls="{{ $helpPopoverId }}"
                    aria-expanded="false"
                    x-bind:aria-expanded="open ? 'true' : 'false'"
                    title="{{ __('help.popover.button') }}"
                    class="flex size-6 items-center justify-center rounded-full border border-gray-300 bg-white text-gray-500 hover:border-blue-300 hover:text-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                ><x-heroicon-o-question-mark-circle class="size-4" aria-hidden="true" /><span class="sr-only">{{ __('help.popover.button') }}</span></button><span
                    id="{{ $helpPopoverId }}"
                    x-show="open"
                    x-transition.opacity
                    style="display: none"
                    role="region"
                    aria-label="{{ __('help.popover.button') }}"
                    class="absolute left-0 top-8 z-30 block w-[min(320px,calc(100vw-32px))] rounded-lg border border-gray-200 bg-white p-4 text-left text-[13px] font-normal leading-5 tracking-normal text-gray-600 shadow-lg"
                ><span class="block">{{ $helpPage['text'] }}</span><a href="{{ route('help', ['guide' => $helpPage['guide']]) }}" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-blue-700 hover:text-blue-900">{{ __('help.popover.link') }}<x-heroicon-o-arrow-right class="size-3.5" aria-hidden="true" /></a></span></span>@endif</h1>
        @if (filled($subtitle))
            <p class="mt-1 text-[13px] text-gray-500">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</header>
