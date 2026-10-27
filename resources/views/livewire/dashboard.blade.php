@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $severityPresentation = [
        'critical' => ['label' => 'Critical', 'icon' => 'exclamation-circle', 'class' => 'bg-red-50 text-[#b91c1c]', 'rail' => 'bg-red-500'],
        'high' => ['label' => 'High', 'icon' => 'exclamation-triangle', 'class' => 'bg-amber-50 text-[#b45309]', 'rail' => 'bg-amber-500'],
        'warning' => ['label' => 'Low', 'icon' => 'information-circle', 'class' => 'bg-blue-50 text-[#1d4ed8]', 'rail' => 'bg-blue-400'],
    ];
@endphp

<div class="flex flex-col gap-6">
    <x-page-header
        heading-id="dashboard-heading"
        :title="'Welcome back, '.$user->name"
        :subtitle="($user->role?->name ?? 'Staff').' · '.$settingsDate->formatInstantDate(now())"
    >
        <x-slot:actions>
            @if ($permittedWidgets !== [] && ! $customising)
                <button type="button" wire:click="startCustomising" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">
                    <x-heroicon-o-adjustments-horizontal class="size-4" aria-hidden="true" />
                    Customise
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($quickActions !== [])
        <nav aria-label="Quick actions" class="flex flex-wrap gap-2">
            @foreach ($quickActions as $action)
                <a wire:key="quick-action-{{ $action['key'] }}" href="{{ $action['url'] }}" @class([
                    'inline-flex items-center gap-1.5 rounded-md px-3.5 py-2 text-[13px] font-semibold',
                    'bg-blue-600 text-white hover:bg-blue-700' => $loop->first,
                    'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $loop->first,
                ])>
                    <x-dynamic-component :component="'heroicon-o-'.$action['icon']" class="size-4" aria-hidden="true" />
                    {{ $action['label'] }}
                </a>
            @endforeach
        </nav>
    @endif

    @if ($customising)
        <section aria-labelledby="customise-heading" class="rounded-lg border border-blue-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-[18px] py-3.5">
                <div>
                    <h2 id="customise-heading" class="text-sm font-bold text-gray-900">Customise your dashboard</h2>
                    <p class="mt-0.5 text-xs text-gray-500">Show, hide and reorder the widgets your role can see.</p>
                </div>
                <button type="button" wire:click="resetLayout" class="text-xs font-semibold text-blue-700 hover:text-blue-900">Reset to role default</button>
            </div>
            @error('layout')
                <p role="alert" class="border-b border-red-100 bg-red-50 px-[18px] py-2.5 text-sm text-red-700">{{ $message }}</p>
            @enderror
            <ol class="divide-y divide-gray-100">
                @foreach ($layoutOrder as $key)
                    @continue(! isset($permittedWidgets[$key]))
                    @php
                        $isShown = ! in_array($key, $layoutHidden, true);
                    @endphp
                    <li wire:key="customise-{{ $key }}" class="flex items-center gap-3 px-[18px] py-2.5">
                        <label class="flex min-w-0 flex-1 items-center gap-2.5 text-[13px] font-medium text-gray-800">
                            <input type="checkbox" wire:click="toggleWidget('{{ $key }}')" @checked($isShown) class="size-4 rounded border-gray-300 text-blue-600">
                            <span class="truncate">{{ $permittedWidgets[$key]->title }}</span>
                            @unless ($isShown)
                                <span class="shrink-0 whitespace-nowrap rounded-full bg-gray-100 px-2 py-0.5 text-[10.5px] font-bold text-gray-500">Hidden</span>
                            @endunless
                        </label>
                        <div class="flex shrink-0 gap-1">
                            <button type="button" wire:click="moveWidget('{{ $key }}', 'up')" @disabled($loop->first) aria-label="Move {{ $permittedWidgets[$key]->title }} up" class="flex size-7 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40">
                                <x-heroicon-o-chevron-up class="size-4" aria-hidden="true" />
                            </button>
                            <button type="button" wire:click="moveWidget('{{ $key }}', 'down')" @disabled($loop->last) aria-label="Move {{ $permittedWidgets[$key]->title }} down" class="flex size-7 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40">
                                <x-heroicon-o-chevron-down class="size-4" aria-hidden="true" />
                            </button>
                        </div>
                    </li>
                @endforeach
            </ol>
            <div class="flex justify-end gap-2 border-t border-gray-100 bg-gray-50 px-[18px] py-3">
                <button type="button" wire:click="cancelCustomising" class="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="button" wire:click="saveLayout" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">Save layout</button>
            </div>
        </section>
    @endif

    @if ($hasModuleWidgets)
        <section aria-labelledby="attention-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-[18px] py-3.5">
                <h2 id="attention-heading" class="flex items-center gap-2 text-[13px] font-bold text-gray-900">
                    <x-heroicon-o-bell-alert class="size-5 text-gray-400" aria-hidden="true" />
                    Needs your attention
                </h2>
                @if ($attentionItems !== [])
                    <span class="text-xs text-gray-500">{{ \App\Support\Plural::count(count($attentionItems), 'item') }}</span>
                @endif
            </div>
            @if ($attentionItems === [])
                <p class="flex items-center gap-2 px-[18px] py-4 text-sm text-gray-600">
                    <x-heroicon-o-check-circle class="size-5 text-green-600" aria-hidden="true" />
                    Nothing needs your attention.
                </p>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach ($attentionItems as $item)
                        @php
                            $severity = $severityPresentation[$item['severity']];
                        @endphp
                        <li wire:key="attention-{{ $item['key'] }}">
                            <a href="{{ $item['url'] }}" class="relative flex items-center gap-3 py-3 pl-[22px] pr-[18px] hover:bg-gray-50">
                                <span aria-hidden="true" @class(['absolute inset-y-2 left-0 w-1 rounded-r', $severity['rail']])></span>
                                <span @class(['inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-bold', $severity['class']])>
                                    <x-dynamic-component :component="'heroicon-o-'.$severity['icon']" class="size-3.5" aria-hidden="true" />
                                    {{ $severity['label'] }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[13px] font-semibold text-gray-900">{{ $item['title'] }}</span>
                                    <span class="block truncate text-xs text-gray-500">{{ $item['detail'] }}</span>
                                </span>
                                <x-heroicon-o-chevron-right class="size-4 shrink-0 text-gray-400" aria-hidden="true" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @else
        <section aria-labelledby="getting-started-heading" class="rounded-lg border border-gray-200 bg-white p-[18px] shadow-sm">
            <h2 id="getting-started-heading" class="flex items-center gap-2 text-[13px] font-bold text-gray-900">
                <x-heroicon-o-lifebuoy class="size-5 text-gray-400" aria-hidden="true" />
                Need something from another team?
            </h2>
            <p class="mt-2 text-sm text-gray-600">
                Your role covers your own team's work, so most of AquaOps is managed by other departments. Your notifications and requests appear below.
                For a one-off need, such as a stock check, a production run or an order, send that team a request. If you need to work in another area regularly, ask for access.
            </p>
            @if (app(\App\Services\Requests\RequestAccess::class)->canSendRequests($user))
                <button type="button" wire:click="$dispatch('open-request-modal')" class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">
                    <x-heroicon-o-paper-airplane class="size-4" aria-hidden="true" />
                    Send a request
                </button>
            @endif
            @if ($requestableModules->isNotEmpty())
                <ul class="mt-4 grid gap-3 sm:grid-cols-3">
                    @foreach ($requestableModules as $module)
                        <li wire:key="request-access-{{ $module->slug }}" class="flex flex-col rounded-md border border-gray-200 p-3">
                            <span class="text-[13px] font-semibold text-gray-900">{{ $module->name }}</span>
                            <span class="mt-0.5 flex-1 text-xs text-gray-500">{{ $module->description }}</span>
                            <form method="POST" action="{{ route('access-requests.store') }}" class="mt-3">
                                @csrf
                                <input type="hidden" name="module" value="{{ $module->slug }}">
                                <button type="submit" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                    <x-heroicon-o-key class="size-3.5" aria-hidden="true" />
                                    Ask for access
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    @if ($widgets === [])
        <p role="status" class="rounded-md border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
            All your widgets are hidden. Select Customise to show them again.
        </p>
    @else
        <div class="grid gap-[18px] sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($widgets as $widget)
                <div wire:key="widget-slot-{{ $widget->key }}" @class(['min-w-0', 'sm:col-span-2' => $widget->isWide()])>
                    @livewire($widget->component, [], key('widget-'.$widget->key))
                </div>
            @endforeach
        </div>
    @endif
</div>
