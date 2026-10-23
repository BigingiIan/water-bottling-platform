@php
    $settingsDate = app(\App\Support\SettingsDate::class);
@endphp

<div class="flex flex-col gap-6" x-data="{ toast: false, toastMessage: '' }" x-on:forecasts-generated.window="toastMessage = $event.detail.message; toast = true; setTimeout(() => toast = false, 4000)">
    <div x-show="toast" x-transition role="status" style="display: none" class="fixed right-4 top-4 z-40 rounded-md border border-green-200 bg-green-50 px-4 py-2.5 text-sm font-medium text-green-800 shadow-lg">
        <span x-text="toastMessage"></span>
    </div>

    <x-page-header title="Forecasting" :subtitle="'Tomorrow\'s demand per SKU from the ML service · '.$settingsDate->formatDate(today()->addDay()->toDateString())">
        @if ($dashboardUrl || $canGenerate)
            <x-slot:actions>
                @if ($dashboardUrl)
                    <a href="{{ $dashboardUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">
                        <x-heroicon-o-arrow-top-right-on-square class="size-4" aria-hidden="true" />
                        Open evaluation dashboard
                    </a>
                @endif
                @if ($canGenerate)
                    <button type="button" wire:click="generateForecasts" wire:loading.attr="disabled" wire:target="generateForecasts" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                        <x-heroicon-o-arrow-path class="size-4" wire:loading.class="animate-spin" wire:target="generateForecasts" aria-hidden="true" />
                        <span wire:loading.remove wire:target="generateForecasts">Generate forecasts</span>
                        <span wire:loading wire:target="generateForecasts">Generating…</span>
                    </button>
                @endif
            </x-slot:actions>
        @endif
    </x-page-header>

    <section aria-label="ML service status" @class([
        'flex flex-wrap items-start gap-x-6 gap-y-2 rounded-lg border px-[18px] py-3.5 text-[13px]',
        'border-green-200 bg-green-50 text-green-900' => $health['reachable'],
        'border-red-200 bg-red-50 text-red-900' => ! $health['reachable'],
    ])>
        <p class="flex items-center gap-2 font-semibold">
            @if ($health['reachable'])
                <x-heroicon-o-check-circle class="size-5 shrink-0" aria-hidden="true" />
                ML service reachable
            @else
                <x-heroicon-o-x-circle class="size-5 shrink-0" aria-hidden="true" />
                ML service unreachable
            @endif
            <span class="font-mono text-xs font-normal opacity-80">{{ $serviceUrl }}</span>
        </p>
        <p>
            @if ($health['reachable'])
                Serving
                @forelse ($health['models'] as $sku => $version)
                    <span class="whitespace-nowrap"><b class="font-semibold">{{ $sku }}</b> <span class="font-mono text-xs">{{ $version }}</span>@if (! $loop->last), @endif</span>
                @empty
                    no trained models.
                @endforelse
            @else
                {{ $health['error'] }} Forecasts cannot be generated until it is running.
            @endif
        </p>
        <p class="sm:ml-auto">Last run: {{ $lastRunAt ? $settingsDate->formatDateTime($lastRunAt) : 'never' }}</p>
    </section>

    @unless ($forecastsEnabled)
        <p role="status" class="flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
            <x-heroicon-o-information-circle class="size-5 shrink-0" aria-hidden="true" />
            ML suggestions are turned off in Settings, so they are hidden on the Production and Inventory pages. Forecasts are still listed here.
        </p>
    @endunless

    @if ($generationResults !== [])
        <section aria-labelledby="generation-results-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-[18px] py-3.5">
                <h2 id="generation-results-heading" class="text-sm font-bold text-gray-900">Generation results</h2>
                <button type="button" wire:click="dismissResults" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" aria-label="Dismiss generation results">
                    <x-heroicon-o-x-mark class="size-5" aria-hidden="true" />
                </button>
            </div>
            <ul class="divide-y divide-gray-100">
                @foreach ($generationResults as $result)
                    <li wire:key="generation-{{ $result['sku'] }}" class="flex items-start gap-3 px-[18px] py-3 text-[13px]">
                        @if ($result['generated'])
                            <x-heroicon-o-check-circle class="mt-px size-5 shrink-0 text-green-600" aria-hidden="true" />
                        @else
                            <x-heroicon-o-exclamation-triangle class="mt-px size-5 shrink-0 text-amber-600" aria-hidden="true" />
                        @endif
                        <span class="w-14 shrink-0 font-semibold text-gray-900">{{ $result['sku'] }}</span>
                        <span class="min-w-0 text-gray-700">
                            <span class="sr-only">{{ $result['generated'] ? 'Generated:' : 'Not generated:' }}</span>
                            {{ $result['message'] }}
                            @if ($result['modelVersion'])
                                <span class="ml-1 font-mono text-xs text-gray-500">{{ $result['modelVersion'] }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($cards->isEmpty())
        <section class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
            <x-heroicon-o-presentation-chart-line class="mx-auto size-8 text-gray-400" aria-hidden="true" />
            <h2 class="mt-3 text-sm font-bold text-gray-900">No products to forecast yet</h2>
            <p class="mt-1 text-sm text-gray-500">Forecasts appear here once products exist. Add stock on the Inventory page to get started.</p>
        </section>
    @else
        <div class="grid gap-[18px] xl:grid-cols-2">
            @foreach ($cards as $card)
                @php
                    $product = $card['product'];
                    $outcome = $card['outcome'];
                    $latest = $card['latest'];
                    $fullyCovered = $card['coveredDays'] >= $card['requiredDays'];
                @endphp
                <article wire:key="forecast-card-{{ $product->id }}" aria-labelledby="forecast-card-{{ $product->id }}-heading" class="flex min-w-0 flex-col gap-4 rounded-lg border border-gray-200 bg-white p-[18px] shadow-sm">
                    <header class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="rounded bg-teal-50 px-2.5 py-[3px] text-[11px] font-bold text-[#0f766e]">{{ $product->unit_type }}</span>
                            <h2 id="forecast-card-{{ $product->id }}-heading" class="mt-2 truncate text-sm font-semibold text-gray-700">{{ $product->name }}</h2>
                        </div>
                        <div class="text-right">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Tomorrow</p>
                            @if ($outcome->isAvailable())
                                <p class="text-2xl font-extrabold tabular-nums text-gray-900">{{ number_format((float) $outcome->prediction) }} <span class="text-xs font-semibold text-gray-400">units</span></p>
                            @else
                                <p class="text-2xl font-extrabold text-gray-300" aria-hidden="true">—</p>
                            @endif
                        </div>
                    </header>

                    @unless ($outcome->isAvailable())
                        <p role="status" class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-[12.5px] text-amber-900">
                            <x-heroicon-o-exclamation-triangle class="mt-px size-4 shrink-0" aria-hidden="true" />
                            <span>
                                <span class="font-semibold">Forecast unavailable.</span> {{ $outcome->message() }}
                                @if ($canGenerate && $outcome->status === \App\Services\Forecasting\ForecastStatus::NotGenerated)
                                    Use <b class="font-semibold">Generate forecasts</b> above.
                                @endif
                            </span>
                        </p>
                    @endunless

                    <dl class="grid grid-cols-3 gap-3 text-[12px]">
                        <div class="min-w-0">
                            <dt class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Model version</dt>
                            <dd class="mt-0.5 truncate font-mono text-gray-800" title="{{ $latest?->model_version ?? $card['servedVersion'] }}">{{ $latest?->model_version ?? $card['servedVersion'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wide text-gray-500">RMSE</dt>
                            <dd class="mt-0.5 tabular-nums text-gray-800">{{ $latest ? number_format((float) $latest->rmse_score, 1) : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Coverage</dt>
                            <dd @class(['mt-0.5 inline-flex items-center gap-1 font-semibold tabular-nums', 'text-green-700' => $fullyCovered, 'text-amber-700' => ! $fullyCovered])>
                                @if ($fullyCovered)
                                    <x-heroicon-o-check-circle class="size-4" aria-hidden="true" />
                                @else
                                    <x-heroicon-o-exclamation-triangle class="size-4" aria-hidden="true" />
                                @endif
                                {{ $card['coveredDays'] }}/{{ $card['requiredDays'] }} days
                            </dd>
                        </div>
                    </dl>

                    <x-forecast-chart :points="$card['points']" :label="$product->unit_type" />
                </article>
            @endforeach
        </div>
    @endif
</div>
