@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $coverWarningDays = (float) app(\App\Services\SettingsService::class)->get('inventory.cover_warning_days', 1.0);
    $hasModuleCards = $production !== null || $stockAlerts !== null || $forecastCover !== null;
    $severityDotClasses = ['info' => 'bg-blue-500', 'warning' => 'bg-amber-500', 'critical' => 'bg-red-500'];
    $emptyClasses = 'flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-gray-500';
@endphp

<div class="flex flex-col gap-6">
    <x-page-header
        heading-id="dashboard-heading"
        :title="'Welcome back, '.$user->name"
        :subtitle="$settingsDate->formatInstantDate(now()).' · Here is what needs your attention today.'"
    />

    @if ($production !== null || $stockAlerts !== null)
        <div class="grid gap-[18px] sm:grid-cols-2 xl:grid-cols-4">
            @if ($production !== null)
                <x-dashboard-card title="Today's output" icon="cube" :href="route('production.schedule')" link-label="Schedule">
                    @if ($production['scheduledRuns'] === 0)
                        <div class="{{ $emptyClasses }}">
                            <p>No runs scheduled today.</p>
                            @if ($canCreateSchedule)
                                <a href="{{ route('production.schedule') }}" class="text-xs font-semibold text-blue-700 hover:text-blue-900">Create a schedule</a>
                            @endif
                        </div>
                    @else
                        <p class="text-2xl font-extrabold tabular-nums text-gray-900">
                            {{ number_format($production['actual']) }}
                            <span class="text-sm font-semibold text-gray-400">/ {{ \App\Support\Plural::count($production['target'], 'unit') }}</span>
                        </p>
                        <div class="mt-3 h-1.5 overflow-hidden rounded bg-gray-100" role="progressbar" aria-valuenow="{{ min(100, $production['percent']) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Output against today's target">
                            <div class="h-full rounded bg-blue-500" style="width: {{ min(100, $production['percent']) }}%"></div>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">{{ $production['percent'] }}% of target across {{ \App\Support\Plural::count($production['scheduledRuns'], 'run') }}</p>
                    @endif
                </x-dashboard-card>

                <x-dashboard-card title="Open runs" icon="play-circle" :href="route('production.runs')" link-label="Runs">
                    @if ($production['openRuns']->isEmpty())
                        <p class="{{ $emptyClasses }}">No open runs today. Every scheduled run is complete.</p>
                    @else
                        <p class="text-2xl font-extrabold tabular-nums text-gray-900">{{ $production['openRuns']->count() }}</p>
                        <ul class="mt-2 space-y-1.5 text-xs text-gray-600">
                            @foreach ($production['openRuns']->take(3) as $run)
                                <li wire:key="dashboard-run-{{ $run->id }}" class="flex items-center justify-between gap-2">
                                    <span class="truncate"><b class="font-semibold text-gray-800">{{ $run->product_sku }}</b> · {{ $run->productionLine?->name ?? 'Line' }} · {{ mb_substr((string) $run->scheduled_time, 0, 5) }}</span>
                                    <span @class(['shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold', 'bg-blue-50 text-[#1d4ed8]' => $run->status === 'active', 'bg-gray-100 text-gray-600' => $run->status !== 'active'])>{{ ucfirst($run->status) }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($production['openRuns']->count() > 3)
                            <p class="mt-1.5 text-xs text-gray-500">and {{ $production['openRuns']->count() - 3 }} more</p>
                        @endif
                    @endif
                </x-dashboard-card>
            @endif

            @if ($stockAlerts !== null)
                <x-dashboard-card title="Stock alerts" icon="archive-box" :href="route('inventory.overview')" link-label="Stock">
                    @if ($stockAlerts->isEmpty())
                        <p class="{{ $emptyClasses }}">All products are above their reorder thresholds.</p>
                    @else
                        <p class="text-2xl font-extrabold tabular-nums text-gray-900">{{ \App\Support\Plural::count($stockAlerts->count(), 'product') }}</p>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($stockAlerts->take(3) as $alert)
                                <li wire:key="dashboard-stock-{{ $alert['product']->id }}" class="flex items-center justify-between gap-2 text-xs text-gray-600">
                                    <span class="truncate"><b class="font-semibold text-gray-800">{{ $alert['product']->unit_type }}</b> · {{ \App\Support\Plural::count($alert['units'], 'unit') }}</span>
                                    <x-stock-status-badge :status="$alert['status']" />
                                </li>
                            @endforeach
                        </ul>
                        @if ($stockAlerts->count() > 3)
                            <p class="mt-1.5 text-xs text-gray-500">and {{ $stockAlerts->count() - 3 }} more</p>
                        @endif
                    @endif
                </x-dashboard-card>

                <x-dashboard-card title="Leakage review" icon="beaker" :href="route('inventory.overview', ['tab' => 'leakage'])" link-label="Leakage">
                    @if ($leakageAttentionCount === 0)
                        <p class="{{ $emptyClasses }}">No leakage reports need review in the last 14 days.</p>
                    @else
                        <p class="text-2xl font-extrabold tabular-nums text-gray-900">{{ \App\Support\Plural::count($leakageAttentionCount, 'report') }}</p>
                        <p class="mt-2 text-xs text-gray-500">Flagged Review or Investigate in the last 14 days.</p>
                    @endif
                </x-dashboard-card>
            @endif
        </div>
    @endif

    <div class="grid gap-[18px] lg:grid-cols-3">
        @if ($forecastCover !== null)
            <x-dashboard-card class="lg:col-span-2" title="Tomorrow's forecast vs stock cover" icon="presentation-chart-line" :href="route('forecasting.index')" link-label="Forecasting">
                @if (! $forecastCover['enabled'])
                    <p class="{{ $emptyClasses }}">ML suggestions are turned off in Settings.</p>
                @elseif ($forecastCover['rows']->isEmpty())
                    <p class="{{ $emptyClasses }}">No products to forecast yet.</p>
                @elseif ($forecastCover['rows']->every(fn (array $row): bool => $row['forecast'] === null))
                    <div class="{{ $emptyClasses }}">
                        <p>No forecasts for tomorrow yet.</p>
                        <a href="{{ route('forecasting.index') }}" class="text-xs font-semibold text-blue-700 hover:text-blue-900">See why on the Forecasting page</a>
                    </div>
                @else
                    <div class="-mx-[18px] -my-4 overflow-x-auto">
                        <table class="w-full min-w-[480px] border-collapse text-left">
                            <caption class="sr-only">Tomorrow's forecast demand, current stock, and days of cover per SKU.</caption>
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">SKU</th>
                                    <th scope="col" class="px-[18px] py-2.5 text-right text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Forecast</th>
                                    <th scope="col" class="px-[18px] py-2.5 text-right text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">In stock</th>
                                    <th scope="col" class="px-[18px] py-2.5 text-right text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Cover</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($forecastCover['rows'] as $row)
                                    @php($lowCover = $row['coverDays'] !== null && $row['coverDays'] < $coverWarningDays)
                                    <tr wire:key="dashboard-cover-{{ $row['product']->id }}" class="border-t border-gray-100 text-[13px]">
                                        <td class="px-[18px] py-2.5"><span class="rounded bg-teal-50 px-2.5 py-[3px] text-[11px] font-bold text-[#0f766e]">{{ $row['product']->unit_type }}</span></td>
                                        <td class="px-[18px] py-2.5 text-right tabular-nums text-gray-800">{{ $row['forecast'] === null ? 'No forecast' : number_format($row['forecast']) }}</td>
                                        <td class="px-[18px] py-2.5 text-right tabular-nums text-gray-800">{{ number_format($row['units']) }}</td>
                                        <td @class(['px-[18px] py-2.5 text-right tabular-nums', 'font-semibold text-amber-700' => $lowCover, 'text-gray-800' => ! $lowCover])>
                                            @if ($row['coverDays'] === null)
                                                —
                                            @else
                                                <span class="inline-flex items-center gap-1">
                                                    @if ($lowCover)<x-heroicon-o-exclamation-triangle class="size-4" aria-hidden="true" /><span class="sr-only">Low cover:</span>@endif
                                                    {{ \App\Support\Plural::count(round($row['coverDays'], 1), 'day') }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-dashboard-card>
        @endif

        <x-dashboard-card class="{{ $forecastCover !== null ? 'lg:col-span-1' : 'lg:col-span-3' }}" title="Latest notifications" icon="bell" :href="route('notifications.index')" link-label="All">
            @if ($notifications->isEmpty())
                <p class="{{ $emptyClasses }}">You're all caught up. New alerts will appear here.</p>
            @else
                <ul class="-mx-[18px] -my-4 divide-y divide-gray-100">
                    @foreach ($notifications as $notification)
                        @php($severity = $notification->data['severity'] ?? 'info')
                        <li wire:key="dashboard-notification-{{ $notification->id }}">
                            <a href="{{ \App\Livewire\NotificationBell::safeUrl($notification->data['url'] ?? null) }}" @class(['flex items-start gap-3 px-[18px] py-3 hover:bg-gray-50', 'bg-blue-50/60' => $notification->read_at === null])>
                                <span aria-hidden="true" @class(['mt-1.5 size-2 shrink-0 rounded-full', $severityDotClasses[$severity] ?? 'bg-blue-500'])></span>
                                <span class="min-w-0">
                                    <span class="block truncate text-[13px] font-medium text-gray-900">
                                        @if ($notification->read_at === null)<span class="sr-only">Unread: </span>@endif{{ $notification->data['title'] ?? 'Notification' }}
                                    </span>
                                    <span class="mt-0.5 block text-[11px] text-gray-400">{{ $notification->created_at?->diffForHumans() }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-dashboard-card>
    </div>

    @unless ($hasModuleCards)
        <p role="status" class="rounded-md border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
            Your role doesn't include production, inventory, or forecasting yet, so there is nothing else to summarise here. Ask an administrator if you need access.
        </p>
    @endunless
</div>
