@php
    $settingsDate = app(\App\Support\SettingsDate::class);
@endphp

<div class="flex flex-col gap-6" x-data="{ toast: false, toastMessage: '' }" x-on:forecasts-generated.window="toastMessage = $event.detail.message; toast = true; setTimeout(() => toast = false, 4000)">
    <div x-show="toast" x-transition role="status" style="display: none" class="fixed right-4 top-4 z-40 rounded-md border border-success/35 bg-success-soft px-4 py-2.5 text-sm font-medium text-success-ink shadow-lg">
        <span x-text="toastMessage"></span>
    </div>

    <x-page-header help="forecasting" title="Forecasting" :subtitle="'Tomorrow\'s demand per SKU from the ML service · '.$settingsDate->formatDate(today()->addDay()->toDateString())">
        @if ($dashboardUrl || $canGenerate)
            <x-slot:actions>
                @if ($dashboardUrl)
                    <a href="{{ $dashboardUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                        <x-heroicon-o-arrow-top-right-on-square class="size-4" aria-hidden="true" />
                        Open evaluation dashboard
                    </a>
                @endif
                @if ($canGenerate)
                    <button type="button" wire:click="generateForecasts" wire:loading.attr="disabled" wire:target="generateForecasts" class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">
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
        'border-success/35 bg-success-soft text-success-ink' => $health['reachable'],
        'border-danger/35 bg-danger-soft text-danger-ink' => ! $health['reachable'],
    ])>
        <p class="flex items-center gap-2 font-semibold">
            @if ($health['reachable'])
                <x-heroicon-o-check-circle class="size-5 shrink-0" aria-hidden="true" />
                ML service reachable
            @else
                <x-heroicon-o-x-circle class="size-5 shrink-0" aria-hidden="true" />
                ML service unreachable
            @endif
            @if ($serviceUrl !== null)
                <span class="font-mono text-xs font-normal">{{ $serviceUrl }}</span>
            @endif
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
        @if ($temperatureSources !== [])
            <p class="w-full text-xs">
                <x-heroicon-o-sun class="mr-0.5 inline size-4 align-[-3px]" aria-hidden="true" />
                {{ __('calendar.forecasting.temperature_source', ['source' => collect($temperatureSources)->map(fn (string $source): string => trans()->has('calendar.forecasting.temperature_sources.'.$source) ? __('calendar.forecasting.temperature_sources.'.$source) : $source)->implode(', ')]) }}
            </p>
        @endif
    </section>

    @if ($missingTermYears !== [])
        <p role="status" class="flex flex-wrap items-start gap-2 rounded-md border border-warning/35 bg-warning-soft px-4 py-2.5 text-sm text-warning-ink">
            <x-heroicon-o-exclamation-triangle class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1">{{ __('calendar.missing_terms', ['years' => implode(', ', $missingTermYears)]) }}</span>
            @if ($canViewCalendar)
                <a href="{{ route('calendar.index', ['tab' => 'terms']) }}" class="font-semibold underline hover:text-warning-ink">{{ __('calendar.forecasting.manage') }}</a>
            @endif
        </p>
    @endif

    @unless ($forecastsEnabled)
        <p role="status" class="flex items-center gap-2 rounded-md border border-warning/35 bg-warning-soft px-4 py-2.5 text-sm text-warning-ink">
            <x-heroicon-o-information-circle class="size-5 shrink-0" aria-hidden="true" />
            ML suggestions are turned off in Settings, so they are hidden on the Production and Inventory pages. Forecasts are still listed here.
        </p>
    @endunless

    @if ($generationResults !== [])
        <section aria-labelledby="generation-results-heading" class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-border px-[18px] py-3.5">
                <h2 id="generation-results-heading" class="text-sm font-bold text-fg">Generation results</h2>
                <button type="button" wire:click="dismissResults" class="rounded-md p-1 text-muted hover:bg-surface-strong hover:text-fg-soft" aria-label="Dismiss generation results">
                    <x-heroicon-o-x-mark class="size-5" aria-hidden="true" />
                </button>
            </div>
            <ul class="divide-y divide-border">
                @foreach ($generationResults as $result)
                    <li wire:key="generation-{{ $result['sku'] }}" class="flex items-start gap-3 px-[18px] py-3 text-[13px]">
                        @if ($result['generated'])
                            <x-heroicon-o-check-circle class="mt-px size-5 shrink-0 text-success-ink" aria-hidden="true" />
                        @else
                            <x-heroicon-o-exclamation-triangle class="mt-px size-5 shrink-0 text-warning-ink" aria-hidden="true" />
                        @endif
                        <span class="w-14 shrink-0 font-semibold text-fg">{{ $result['sku'] }}</span>
                        <span class="min-w-0 text-fg-soft">
                            <span class="sr-only">{{ $result['generated'] ? 'Generated:' : 'Not generated:' }}</span>
                            {{ $result['message'] }}
                            @if ($result['modelVersion'])
                                <span class="ml-1 font-mono text-xs text-muted">{{ $result['modelVersion'] }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($cards->isEmpty())
        <section class="rounded-lg border border-dashed border-border-strong bg-surface px-6 py-12 text-center">
            <x-heroicon-o-presentation-chart-line class="mx-auto size-8 text-muted" aria-hidden="true" />
            <h2 class="mt-3 text-sm font-bold text-fg">No products to forecast yet</h2>
            <p class="mt-1 text-sm text-muted">Forecasts appear here once products exist. Add stock on the Inventory page to get started.</p>
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
                <article wire:key="forecast-card-{{ $product->id }}" aria-labelledby="forecast-card-{{ $product->id }}-heading" class="flex min-w-0 flex-col gap-4 rounded-lg border border-border bg-surface p-[18px] shadow-sm">
                    <header class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="rounded bg-tag-soft px-2.5 py-[3px] text-[11px] font-bold text-tag-ink">{{ $product->unit_type }}</span>
                            <h2 id="forecast-card-{{ $product->id }}-heading" class="mt-2 truncate text-sm font-semibold text-fg-soft">{{ $product->name }}</h2>
                        </div>
                        <div class="text-right">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-muted">Tomorrow</p>
                            @if ($outcome->isAvailable())
                                <p class="text-2xl font-extrabold tabular-nums text-fg">{{ number_format((float) $outcome->prediction) }} <span class="text-xs font-semibold text-muted">units</span></p>
                            @else
                                <p class="text-2xl font-extrabold text-subtle" aria-hidden="true">—</p>
                            @endif
                        </div>
                    </header>

                    @if (($calendarChips[$product->id] ?? []) !== [])
                        <ul aria-label="Calendar days ahead" class="-mt-1 flex flex-wrap gap-1.5">
                            @foreach ($calendarChips[$product->id] as $chip)
                                <li wire:key="forecast-chip-{{ $product->id }}-{{ $chip['kind'] }}-{{ $chip['date']->toDateString() }}" @class([
                                    'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-semibold',
                                    'bg-warning-soft text-warning-ink' => $chip['kind'] === 'promotion',
                                    'bg-danger-soft text-danger-ink' => $chip['kind'] === 'holiday',
                                ])>
                                    <x-dynamic-component :component="$chip['kind'] === 'promotion' ? 'heroicon-o-tag' : 'heroicon-o-flag'" class="size-3.5" aria-hidden="true" />
                                    {{ $chip['label'] }} · {{ $chip['date']->isTomorrow() ? 'tomorrow' : $chip['date']->format('D j M') }}
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @unless ($outcome->isAvailable())
                        <p role="status" class="flex items-start gap-2 rounded-md border border-warning/35 bg-warning-soft px-3 py-2.5 text-[12.5px] text-warning-ink">
                            <x-heroicon-o-exclamation-triangle class="mt-px size-4 shrink-0" aria-hidden="true" />
                            <span>
                                <span class="font-semibold">Forecast unavailable.</span> {{ $describeFailure($outcome->message()) }}
                                @if ($canGenerate && $outcome->status === \App\Services\Forecasting\ForecastStatus::NotGenerated)
                                    Use <b class="font-semibold">Generate forecasts</b> above.
                                @endif
                            </span>
                        </p>
                    @endunless

                    <dl class="grid grid-cols-3 gap-3 text-[12px]">
                        <div class="min-w-0">
                            <dt class="text-[11px] font-bold uppercase tracking-wide text-muted">Model version</dt>
                            <dd class="mt-0.5 truncate font-mono text-fg" title="{{ $latest?->model_version ?? $card['servedVersion'] }}">{{ $latest?->model_version ?? $card['servedVersion'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wide text-muted">RMSE</dt>
                            <dd class="mt-0.5 tabular-nums text-fg">{{ $latest ? number_format((float) $latest->rmse_score, 1) : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wide text-muted">Coverage</dt>
                            <dd @class(['mt-0.5 inline-flex items-center gap-1 font-semibold tabular-nums', 'text-success-ink' => $fullyCovered, 'text-warning-ink' => ! $fullyCovered])>
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
