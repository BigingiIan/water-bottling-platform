@php
    $levelClasses = [
        'none' => 'bg-gray-100 text-gray-600',
        'view' => 'bg-amber-100 text-amber-900',
        'full' => 'bg-blue-600 text-white',
    ];
    $levelIcons = ['none' => 'no-symbol', 'view' => 'eye', 'full' => 'pencil-square'];
    $guideLink = fn (string $slug, bool $isCurrent): string => 'flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-[13px] '
        .($isCurrent ? 'bg-blue-50 font-semibold text-blue-800' : 'text-gray-700 hover:bg-gray-50');
@endphp

<div class="flex flex-col gap-6">
    <x-page-header heading-id="help-heading" :title="__('help.centre.title')" :subtitle="__('help.centre.subtitle')">
        <x-slot:actions>
            <button type="button" wire:click="$dispatch('replay-tour')" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">
                <x-heroicon-o-play-circle class="size-4" aria-hidden="true" />
                {{ __('help.centre.replay_tour') }}
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="relative max-w-xl">
        <label for="help-search" class="sr-only">{{ __('help.centre.search_label') }}</label>
        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" aria-hidden="true" />
        <input
            id="help-search"
            type="search"
            wire:model.live.debounce.250ms="search"
            placeholder="{{ __('help.centre.search_placeholder') }}"
            class="w-full rounded-md border border-gray-300 bg-white py-2.5 pl-10 pr-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
        >
    </div>

    <div class="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
        <nav aria-labelledby="help-guides-heading" class="flex flex-col gap-4">
            <h2 id="help-guides-heading" class="sr-only">{{ __('help.centre.guides_heading') }}</h2>
            @foreach ([__('help.centre.general_heading') => $generalGuides, __('help.centre.modules_heading') => $moduleGuides] as $groupLabel => $groupGuides)
                @if ($groupGuides !== [])
                    <div>
                        <h3 class="px-3 pb-1.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">{{ $groupLabel }}</h3>
                        <ul class="flex flex-col gap-0.5">
                            @foreach ($groupGuides as $listedGuide)
                                <li wire:key="help-nav-{{ $listedGuide['slug'] }}">
                                    <button type="button" wire:click="openGuide('{{ $listedGuide['slug'] }}')" @if ($search === '' && $currentGuide && $currentGuide['slug'] === $listedGuide['slug']) aria-current="page" @endif class="{{ $guideLink($listedGuide['slug'], $search === '' && $currentGuide && $currentGuide['slug'] === $listedGuide['slug']) }}">
                                        {{ $listedGuide['title'] }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        </nav>

        <div class="flex min-w-0 flex-col gap-6">
            @if ($searchResults !== null)
                <section aria-labelledby="help-results-heading" class="rounded-lg border border-gray-200 bg-white">
                    <h2 id="help-results-heading" class="border-b border-gray-100 px-[18px] py-3.5 text-sm font-bold text-gray-900">
                        {{ trans_choice('help.centre.search_results', count($searchResults), ['count' => count($searchResults), 'query' => trim($search)]) }}
                    </h2>
                    @if ($searchResults === [])
                        <p class="px-[18px] py-5 text-sm text-gray-600">{{ __('help.centre.search_empty', ['query' => trim($search)]) }}</p>
                    @else
                        <ul class="divide-y divide-gray-100">
                            @foreach ($searchResults as $result)
                                <li wire:key="help-result-{{ $result['section'] }}">
                                    <a href="{{ route('help', ['guide' => $result['guide']]) }}#{{ $result['section'] }}" class="block px-[18px] py-3 hover:bg-gray-50">
                                        <span class="block text-[13px] font-semibold text-gray-900">{{ $result['sectionTitle'] }}</span>
                                        <span class="block text-[11.5px] font-medium text-blue-700">{{ $result['guideTitle'] }}</span>
                                        <span class="mt-0.5 block text-xs text-gray-500">{{ $result['snippet'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @elseif ($currentGuide)
                <article aria-labelledby="help-guide-title" class="rounded-lg border border-gray-200 bg-white">
                    <header class="border-b border-gray-100 px-[18px] py-4 sm:px-6">
                        <h2 id="help-guide-title" class="text-lg font-bold text-gray-900">{{ $currentGuide['title'] }}</h2>
                        <p class="mt-0.5 text-[13px] text-gray-500">{{ $currentGuide['summary'] }}</p>
                        <nav aria-label="{{ __('help.centre.on_this_page') }}" class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                            @foreach (collect($currentGuide['sections'])->where('level', 2) as $section)
                                <a href="#{{ $section['id'] }}" class="font-semibold text-blue-700 hover:text-blue-900 hover:underline">{{ $section['title'] }}</a>
                            @endforeach
                        </nav>
                    </header>
                    <div class="help-prose flex flex-col gap-4 px-[18px] py-5 text-sm leading-6 text-gray-700 sm:px-6">
                        @foreach ($currentGuide['sections'] as $section)
                            <section wire:key="help-section-{{ $section['id'] }}" id="{{ $section['id'] }}" aria-labelledby="{{ $section['id'] }}-title" class="scroll-mt-24">
                                @if ($section['level'] === 2)
                                    <h3 id="{{ $section['id'] }}-title" class="text-[15px] font-bold text-gray-900">{{ $section['title'] }}</h3>
                                @else
                                    <h4 id="{{ $section['id'] }}-title" class="text-[13.5px] font-semibold text-gray-900">{{ $section['title'] }}</h4>
                                @endif
                                @if ($section['html'] !== '')
                                    <div class="mt-1.5">{!! $section['html'] !!}</div>
                                @endif
                            </section>
                        @endforeach
                    </div>
                </article>
            @endif

            <section aria-labelledby="help-capabilities-heading" class="rounded-lg border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-[18px] py-3.5">
                    <h2 id="help-capabilities-heading" class="text-sm font-bold text-gray-900">{{ __('help.centre.capabilities_heading') }}</h2>
                    <p class="mt-0.5 text-xs text-gray-500">{{ __('help.centre.capabilities_intro', ['role' => $user->role?->name ?? __('help.levels.none')]) }}</p>
                </div>
                <ul class="divide-y divide-gray-100">
                    @foreach ($capabilities as $capability)
                        <li wire:key="capability-{{ $capability['slug'] }}" class="flex flex-col gap-1.5 px-[18px] py-3 sm:flex-row sm:items-center sm:gap-4">
                            <span class="w-40 shrink-0 text-[13px] font-semibold text-gray-900">{{ $capability['name'] }}</span>
                            <span @class(['inline-flex w-fit shrink-0 items-center gap-1 whitespace-nowrap rounded px-2 py-0.5 text-[11px] font-semibold', $levelClasses[$capability['level']]])>
                                <x-dynamic-component :component="'heroicon-o-'.$levelIcons[$capability['level']]" class="size-3.5" aria-hidden="true" />
                                {{ __('help.levels.'.$capability['level']) }}
                            </span>
                            <span class="min-w-0 flex-1 text-[13px] text-gray-600">{{ $capability['description'] }}</span>
                            @if ($capability['guide'])
                                <button type="button" wire:click="openGuide('{{ $capability['guide'] }}')" class="shrink-0 text-xs font-semibold text-blue-700 hover:text-blue-900">{{ __('help.centre.open_guide') }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </div>
</div>
