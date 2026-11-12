@php
    $canSendRequests = app(\App\Services\Requests\RequestAccess::class)->canSendRequests(auth()->user());
    $hasStockActions = $canWriteInventory || $canSendRequests;
@endphp
<div class="flex flex-col gap-6">
    @if (session('inventorySuccess'))
        <p role="status" class="rounded-md border border-success/35 bg-success-soft px-4 py-2.5 text-sm font-medium text-success-ink">{{ session('inventorySuccess') }}</p>
    @endif

    <x-page-header help="inventory" title="Inventory" subtitle="Production, leakage, and stock activity.">
        @if ($canWriteInventory && $activeTab === 'stock')
            <x-slot:actions>
                <button type="button" wire:click="openStockCountModal" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                    <x-heroicon-o-clipboard-document-check class="size-4" aria-hidden="true" />
                    Record stock count
                </button>
                <button type="button" wire:click="openAdjustmentModal" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                    <x-heroicon-o-adjustments-horizontal class="size-4" aria-hidden="true" />
                    Adjust Stock
                </button>
                <button type="button" wire:click="openStockInModal" class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">
                    <x-heroicon-o-plus class="size-4" aria-hidden="true" />
                    Record Stock-In
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <nav aria-label="Inventory sections" class="flex flex-wrap gap-1 border-b border-border">
        <button type="button" wire:click="$set('activeTab', 'stock')" @class(['border-b-2 px-3 py-2.5 text-[13px] font-semibold', 'border-accent text-accent-ink' => $activeTab === 'stock', 'border-transparent text-muted hover:text-fg' => $activeTab !== 'stock']) @if ($activeTab === 'stock') aria-current="page" @endif>Stock Overview</button>
        <button type="button" wire:click="$set('activeTab', 'batches')" @class(['border-b-2 px-3 py-2.5 text-[13px] font-semibold', 'border-accent text-accent-ink' => $activeTab === 'batches', 'border-transparent text-muted hover:text-fg' => $activeTab !== 'batches']) @if ($activeTab === 'batches') aria-current="page" @endif>Batches</button>
        <button type="button" wire:click="$set('activeTab', 'leakage')" @class(['border-b-2 px-3 py-2.5 text-[13px] font-semibold', 'border-accent text-accent-ink' => $activeTab === 'leakage', 'border-transparent text-muted hover:text-fg' => $activeTab !== 'leakage']) @if ($activeTab === 'leakage') aria-current="page" @endif>
            Leakage Reports
            @if ($leakageAttentionCount > 0)
                <span aria-label="{{ $leakageAttentionCount }} leakage reports need attention in the last 14 days" class="ml-1 rounded-full bg-warning-soft px-1.5 py-0.5 text-[10px] font-bold text-warning-ink">{{ $leakageAttentionCount }}</span>
            @endif
        </button>
        <button type="button" wire:click="$set('activeTab', 'movements')" @class(['border-b-2 px-3 py-2.5 text-[13px] font-semibold', 'border-accent text-accent-ink' => $activeTab === 'movements', 'border-transparent text-muted hover:text-fg' => $activeTab !== 'movements']) @if ($activeTab === 'movements') aria-current="page" @endif>Movements</button>
    </nav>

    @if ($activeTab === 'stock')
    @if (! $forecastEnabled)
        <p role="status" class="rounded-md border border-info/20 bg-info-soft px-4 py-2.5 text-sm text-info-ink">ML forecast suggestions are disabled in Settings.</p>
    @endif
    <section aria-label="Product stock cover" class="grid grid-cols-[repeat(auto-fit,minmax(210px,1fr))] gap-3.5">
        @forelse ($productCards as $card)
            @php
                $product = $card['product'];
                $status = $card['status'];
                $coverFillClass = match ($status) {
                    'out', 'critical' => 'bg-danger',
                    'low' => 'bg-warning',
                    default => 'bg-success',
                };
                $coverPercent = $card['coverDays'] === null ? 0 : min(100, $card['coverDays'] / 30 * 100);
            @endphp
            <article @class([
                'min-w-0 rounded-lg border bg-surface p-4',
                'border-danger' => in_array($status, ['critical', 'out'], true),
                'border-warning' => $status === 'low',
                'border-border' => $status === 'ok',
            ])>
                <div class="mb-2.5 flex items-center justify-between gap-2">
                    <span class="rounded bg-tag-soft px-2.5 py-[3px] text-[11px] font-bold text-tag-ink">{{ $product->unit_type }}</span>
                    <x-stock-status-badge :status="$status" />
                </div>
                <h2 class="truncate text-sm font-semibold text-fg-soft">{{ $product->name }}</h2>
                <p class="mt-1 text-2xl font-extrabold tabular-nums text-fg">
                    {{ number_format($card['totalUnits']) }} <span class="text-xs font-semibold text-muted">units</span>
                </p>
                <p class="mt-0.5 text-[11.5px] text-muted">Reorder threshold: {{ number_format((float) $product->reorder_threshold) }}</p>
                <div class="mt-3">
                    <div class="flex items-baseline justify-between gap-2 text-[11.5px] text-muted">
                        <span>Cover at forecast</span>
                        @if (! $forecastEnabled)
                            <span class="font-semibold text-muted">Disabled in Settings</span>
                        @elseif ($card['coverDays'] === null)
                            <span class="font-semibold text-muted">No forecast</span>
                        @else
                            <span @class(['font-semibold tabular-nums', 'text-warning-ink' => $card['coverWarning'], 'text-fg' => ! $card['coverWarning']])>
                                {{ number_format($card['coverDays'], 1) }} days{{ $card['coverWarning'] ? ' — low cover' : '' }}
                            </span>
                        @endif
                    </div>
                    <div class="mt-1.5 h-1.5 overflow-hidden rounded bg-surface-strong" @if ($card['coverDays'] !== null) role="progressbar" aria-valuenow="{{ (int) round($coverPercent) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Forecast cover for {{ $product->unit_type }}" @endif>
                        <div class="h-full rounded {{ $coverFillClass }}" style="width: {{ $coverPercent }}%"></div>
                    </div>
                </div>
            </article>
        @empty
            <p class="col-span-full rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted">No products have been added.</p>
        @endforelse
    </section>

    <section aria-labelledby="stock-table-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
        <div class="border-b border-border px-[18px] py-3.5">
            <h2 id="stock-table-heading" class="text-sm font-bold text-fg">Warehouse stock</h2>
            <p class="mt-0.5 text-xs text-muted">Stock by warehouse line. Status reflects the total across all warehouse lines for each product.</p>
        </div>
        <form method="GET" action="{{ route('inventory.overview') }}" class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-muted px-[18px] py-3">
            <label class="sr-only" for="inventory-product-filter">Filter by product</label>
            <select id="inventory-product-filter" name="productFilter" wire:model.live="productFilter" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft">
                <option value="">All products</option>
                @foreach ($productCards as $card)
                    <option value="{{ $card['product']->id }}">{{ $card['product']->unit_type }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="inventory-location-filter">Filter by location</label>
            <select id="inventory-location-filter" name="locationFilter" wire:model.live="locationFilter" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft">
                <option value="">All locations</option>
                @foreach ($locations as $location)
                    <option value="{{ $location }}">{{ $location }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="inventory-status-filter">Filter by product status</label>
            <select id="inventory-status-filter" name="statusFilter" wire:model.live="statusFilter" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft">
                <option value="">All statuses</option>
                <option value="ok">OK</option>
                <option value="low">Low</option>
                <option value="critical">Critical</option>
                <option value="out">Out of stock</option>
            </select>
            <span class="ml-auto text-xs text-muted">{{ \App\Support\Plural::count($stockLines->count(), 'stock line') }}</span>
        </form>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[800px] border-collapse text-left">
                <caption class="sr-only">Stock levels and product-level statuses by warehouse line</caption>
                <thead class="bg-surface-muted">
                    <tr>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Product</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Batch</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Location</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Level / minimum</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Status</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Last updated</th>
                        @if ($hasStockActions)
                            <th scope="col" class="border-b border-border px-[18px] py-2.5"><span class="sr-only">Actions</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockLines as $stockLine)
                        @php
                            $lineLevel = (float) $stockLine->current_level;
                            $lineMinimum = (float) ($stockLine->minimum_threshold ?? 0);
                            $lineScale = max($lineLevel, $lineMinimum, 1);
                            $lineFill = min(100, $lineLevel / $lineScale * 100);
                            $minimumMark = min(100, $lineMinimum / $lineScale * 100);
                            $status = $statusByProduct[$stockLine->product_id]['status'] ?? 'ok';
                        @endphp
                        <tr wire:key="stock-line-{{ $stockLine->id }}" class="transition-colors hover:bg-surface-muted">
                            <td class="border-b border-border px-[18px] py-3">
                                <span class="rounded bg-tag-soft px-2.5 py-[3px] text-[11px] font-bold text-tag-ink">{{ $stockLine->product->unit_type }}</span>
                            </td>
                            <td class="border-b border-border px-[18px] py-3 font-mono text-xs text-fg-soft">B-{{ str_pad((string) $stockLine->batch_id, 5, '0', STR_PAD_LEFT) }}</td>
                            <td class="border-b border-border px-[18px] py-3 text-[13px] text-fg-soft">{{ $stockLine->warehouse_location ?: '—' }}</td>
                            <td class="border-b border-border px-[18px] py-3">
                                <div class="flex min-w-[150px] items-center gap-2.5">
                                    <div class="relative h-1.5 flex-1 rounded bg-surface-strong">
                                        <div class="h-full rounded bg-accent" style="width: {{ $lineFill }}%"></div>
                                        <span aria-hidden="true" class="absolute -top-[3px] -bottom-[3px] w-0.5 rounded bg-fg-soft/60" style="left: {{ $minimumMark }}%"></span>
                                    </div>
                                    <span class="whitespace-nowrap font-mono text-xs text-fg-soft">{{ number_format($lineLevel) }} / {{ number_format($lineMinimum) }}</span>
                                </div>
                            </td>
                            <td class="border-b border-border px-[18px] py-3">
                                <x-stock-status-badge :status="$status" />
                            </td>
                            <td class="border-b border-border px-[18px] py-3 text-xs text-muted">{{ app(\App\Support\SettingsDate::class)->formatDateTime($stockLine->last_updated) }}</td>
                            @if ($hasStockActions)
                                <td class="border-b border-border px-[18px] py-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        @if ($canWriteInventory)
                                            <button type="button" wire:click="openAdjustmentModal({{ $stockLine->id }})" class="rounded-md px-2.5 py-1 text-xs font-semibold text-fg-soft hover:bg-surface-strong">Adjust</button>
                                        @endif
                                        @if ($canSendRequests)
                                            <x-send-request-button related-type="stock_line" :related-id="$stockLine->id" compact label="Send request about this stock line" />
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $hasStockActions ? 7 : 6 }}"><x-empty-state key="stock_lines" icon="archive-box" :filtered="$productFilter !== '' || $locationFilter !== '' || $statusFilter !== ''" :can="$canWriteInventory" action-click="openStockInModal" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid items-start gap-[18px] lg:grid-cols-[1.6fr_1fr]">
        <section aria-labelledby="attention-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-[18px] py-3.5">
                <h2 id="attention-heading" class="text-sm font-bold text-fg">Needs attention</h2>
                <p class="mt-0.5 text-xs text-muted">Products below reorder thresholds or forecast cover warnings, critical items first.</p>
            </div>
            @forelse ($attentionProducts as $card)
                @php
                    $product = $card['product'];
                    $critical = in_array($card['status'], ['critical', 'out'], true);
                    $suggestedUnits = $card['forecastDemand'] !== null
                        ? (int) (ceil($card['forecastDemand'] / 10) * 10)
                        : null;
                @endphp
                <article wire:key="attention-{{ $product->id }}" class="flex items-start gap-3 border-b border-border px-[18px] py-3.5 last:border-b-0">
                    <span aria-hidden="true" class="mt-1.5 size-[7px] shrink-0 rounded-full {{ $critical ? 'bg-danger' : 'bg-warning' }}"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[12.5px] text-fg-soft"><strong class="text-fg">{{ $product->unit_type }}</strong> · {{ $card['coverWarning'] && $card['status'] === 'ok' ? 'Low forecast cover' : match ($card['status']) { 'out' => 'Out of stock', 'critical' => 'Critical', default => 'Low' } }} at {{ \App\Support\Plural::count($card['totalUnits'], 'unit') }}</p>
                        <p class="mt-0.5 text-[11px] text-muted">
                            Cover: {{ ! $forecastEnabled ? 'Disabled in Settings' : ($card['coverDays'] === null ? 'No forecast' : number_format($card['coverDays'], 1).' days') }}
                            @if ($forecastEnabled)
                                · Suggested run: {{ $suggestedUnits === null ? 'No forecast' : number_format($suggestedUnits).' units' }}
                            @endif
                        </p>
                        <a href="{{ route('production.schedule', ['sku' => $product->unit_type]) }}" class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-accent-ink hover:text-accent-ink">Plan a run <x-heroicon-o-arrow-right class="size-3.5" aria-hidden="true" /></a>
                    </div>
                </article>
            @empty
                <p class="px-[18px] py-7 text-center text-sm text-muted">All products are at or above their reorder thresholds.</p>
            @endforelse
        </section>

        <section aria-labelledby="movements-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-[18px] py-3.5">
                <h2 id="movements-heading" class="text-sm font-bold text-fg">Recent movements</h2>
                <p class="mt-0.5 text-xs text-muted">Latest stock adjustments recorded.</p>
            </div>
            @forelse ($recentMovements as $movement)
                <article wire:key="movement-{{ $movement->id }}" class="flex items-start gap-3 border-b border-border px-[18px] py-3.5 last:border-b-0">
                    <span aria-hidden="true" class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full {{ $movement->quantity_change < 0 ? 'bg-warning-soft text-warning-ink' : 'bg-success-soft text-success-ink' }}">@if ($movement->quantity_change < 0)<x-heroicon-o-minus class="size-4" />@else<x-heroicon-o-plus class="size-4" />@endif</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[12.5px] text-fg-soft"><strong class="text-fg">{{ $movement->product?->unit_type ?? 'Product' }}</strong> · {{ str_replace('_', ' ', ucfirst($movement->type)) }}</p>
                        <p class="mt-0.5 text-[11px] text-muted">
                            {{ $movement->reason ?: ($movement->user?->name ?? 'System') }}
                            · {{ $movement->created_at?->diffForHumans() ?? '—' }}
                        </p>
                    </div>
                    <span class="whitespace-nowrap font-mono text-xs font-semibold {{ $movement->quantity_change < 0 ? 'text-warning-ink' : 'text-success-ink' }}">{{ $movement->quantity_change > 0 ? '+' : '' }}{{ number_format($movement->quantity_change) }}</span>
                </article>
            @empty
                <p class="px-[18px] py-7 text-center text-sm text-muted">No stock movements recorded yet.</p>
            @endforelse
        </section>
    </div>
    @elseif ($activeTab === 'batches')
        <section aria-labelledby="batches-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-[18px] py-3.5">
                <h2 id="batches-heading" class="text-sm font-bold text-fg">Production batches</h2>
                <p class="mt-0.5 text-xs text-muted">Production output and yield for the selected date range.</p>
            </div>
            <div class="flex flex-wrap items-end gap-3 border-b border-border bg-surface-muted px-[18px] py-3">
                <label class="flex flex-col gap-1 text-[11px] font-semibold text-fg-soft">From
                    <input type="date" wire:model.live="batchDateFrom" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
                </label>
                <label class="flex flex-col gap-1 text-[11px] font-semibold text-fg-soft">To
                    <input type="date" wire:model.live="batchDateTo" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
                </label>
                <label class="flex flex-col gap-1 text-[11px] font-semibold text-fg-soft">Status
                    <select wire:model.live="batchStatus" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
                        <option value="">All statuses</option>
                        @foreach (['pending', 'in_progress', 'completed', 'rejected'] as $status)
                            <option value="{{ $status }}">{{ str_replace('_', ' ', ucfirst($status)) }}</option>
                        @endforeach
                    </select>
                </label>
                <span class="ml-auto text-xs text-muted">{{ $batchRows->total() }} batches</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[780px] border-collapse text-left">
                    <thead class="bg-surface-muted">
                        <tr>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Batch / date</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Raw water</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Bottled</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Yield</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Status</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Operator</th>
                            @if ($canSendRequests)
                                <th scope="col" class="px-[18px] py-2.5"><span class="sr-only">Actions</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batchRows as $batch)
                            @php
                                $yieldPercent = (float) $batch->raw_water_volume > 0
                                    ? (float) $batch->bottled_volume / (float) $batch->raw_water_volume * 100
                                    : 0;
                                $batchBadgeClass = match ($batch->status) {
                                    'completed' => 'bg-success-soft text-success-ink',
                                    'in_progress' => 'bg-accent-soft text-accent-ink',
                                    'rejected' => 'bg-danger-soft text-danger-ink',
                                    default => 'bg-warning-soft text-warning-ink',
                                };
                            @endphp
                            <tr wire:key="production-batch-{{ $batch->id }}" class="border-t border-border text-[13px] text-fg-soft">
                                <td class="px-[18px] py-3"><span class="font-mono text-xs font-semibold text-fg">B-{{ str_pad((string) $batch->id, 5, '0', STR_PAD_LEFT) }}</span><span class="mt-0.5 block text-xs text-muted">{{ app(\App\Support\SettingsDate::class)->formatDate($batch->batch_date) }}</span></td>
                                <td class="px-[18px] py-3 tabular-nums">{{ number_format((float) $batch->raw_water_volume, 2) }} L</td>
                                <td class="px-[18px] py-3 tabular-nums">{{ number_format((float) $batch->bottled_volume, 2) }} L</td>
                                <td class="px-[18px] py-3 tabular-nums">{{ number_format($yieldPercent, 1) }}%</td>
                                <td class="px-[18px] py-3"><span class="rounded-full px-2.5 py-1 text-[11px] font-bold {{ $batchBadgeClass }}">{{ str_replace('_', ' ', ucfirst($batch->status)) }}</span></td>
                                <td class="px-[18px] py-3">{{ $batch->user?->name ?? '—' }}</td>
                                @if ($canSendRequests)
                                    <td class="px-[18px] py-3 text-right">
                                        <x-send-request-button related-type="batch" :related-id="$batch->id" compact label="Send request about batch B-{{ str_pad((string) $batch->id, 5, '0', STR_PAD_LEFT) }}" />
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canSendRequests ? 7 : 6 }}"><x-empty-state key="batches" icon="cube" :filtered="$batchStatus !== ''" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-border px-[18px] py-3">{{ $batchRows->links() }}</div>
        </section>
    @elseif ($activeTab === 'leakage')
        <section aria-labelledby="loss-trend-heading" class="rounded-lg border border-border bg-surface p-[18px]">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 id="loss-trend-heading" class="text-sm font-bold text-fg">Process loss trend</h2>
                    <p class="mt-0.5 text-xs text-muted">Loss beyond the normal process loss, per product, last 30 completed batches.</p>
                </div>
                <p class="flex items-center gap-3 text-[11px] text-muted">
                    <span class="inline-flex items-center gap-1.5"><svg width="16" height="6" aria-hidden="true"><line x1="1" y1="3" x2="15" y2="3" class="stroke-subtle" stroke-width="1" /></svg>0% = normal loss ({{ number_format($normalLossPercent, 1) }}%)</span>
                    <span class="inline-flex items-center gap-1.5"><svg width="16" height="6" aria-hidden="true"><line x1="1" y1="3" x2="15" y2="3" class="stroke-warning" stroke-width="1" stroke-dasharray="3 3" /></svg>Review at {{ number_format($reviewPercent, 1) }}% beyond normal</span>
                </p>
            </div>
            @if ($lossTrends->isEmpty())
                <p class="mt-3 text-sm text-muted">Loss trends appear once production runs are completed with their water volumes.</p>
            @else
                <ul class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    @foreach ($lossTrends as $sku => $values)
                        <li wire:key="loss-trend-{{ $sku }}" class="min-w-0">
                            <p class="flex items-baseline justify-between text-xs"><span class="font-bold text-fg">{{ $sku }}</span><span class="tabular-nums text-muted">latest +{{ number_format(end($values), 1) }}% · {{ \App\Support\Plural::count(count($values), 'batch') }}</span></p>
                            <x-loss-sparkline :values="$values" :label="$sku" :normal="$normalLossPercent" :review="$reviewPercent" class="mt-1" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="leakage-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-[18px] py-3.5">
                <div>
                    <h2 id="leakage-heading" class="text-sm font-bold text-fg">Leakage reports</h2>
                    <p class="mt-0.5 text-xs text-muted">Entered by hand, raised when a run is completed (process loss), or raised by a stock count (shrinkage).</p>
                </div>
                @if ($canWriteInventory)
                    <button type="button" wire:click="openLeakageReportModal" class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover"><x-heroicon-o-plus class="size-4" aria-hidden="true" />New Report</button>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-muted px-[18px] py-3">
                <label for="leakage-flag-filter" class="sr-only">Filter by severity</label>
                <select id="leakage-flag-filter" wire:model.live="leakageFlagFilter" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft">
                    <option value="">All severities</option>
                    <option value="Normal">Normal</option>
                    <option value="Review">Review</option>
                    <option value="Investigate">Investigate</option>
                </select>
                <label for="leakage-source-filter" class="sr-only">Filter by source</label>
                <select id="leakage-source-filter" wire:model.live="leakageSourceFilter" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft">
                    <option value="">All sources</option>
                    <option value="manual">Manual</option>
                    <option value="process">Process loss</option>
                    <option value="stock">Stock count</option>
                </select>
                <label for="leakage-status-filter" class="sr-only">Filter by status</label>
                <select id="leakage-status-filter" wire:model.live="leakageStatusFilter" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft">
                    <option value="">All statuses</option>
                    <option value="open">Open</option>
                    <option value="acknowledged">Acknowledged</option>
                    <option value="resolved">Resolved</option>
                </select>
                <span class="ml-auto text-xs text-muted">{{ \App\Support\Plural::count($leakageReports->total(), 'report') }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1080px] border-collapse text-left">
                    <caption class="sr-only">Leakage reports with source, loss percentage, severity, status, and explanation.</caption>
                    <thead class="bg-surface-muted">
                        <tr>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Date / subject</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Source</th>
                            <th scope="col" class="px-[18px] py-2.5 text-right text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Loss</th>
                            <th scope="col" class="px-[18px] py-2.5 text-right text-[11px] font-bold uppercase tracking-[0.4px] text-muted">z</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Flag</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Status</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Explanation</th>
                            <th scope="col" class="px-[18px] py-2.5"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($leakageReports as $report)
                            @php
                                $flag = $report->flag();
                                $flagClass = match ($flag) {
                                    'Investigate' => 'bg-danger-soft text-danger-ink',
                                    'Review' => 'bg-warning-soft text-warning-ink',
                                    default => 'bg-success-soft text-success-ink',
                                };
                                $statusClass = match ($report->status) {
                                    'resolved' => 'bg-success-soft text-success-ink',
                                    'acknowledged' => 'bg-accent-soft text-accent-ink',
                                    default => 'bg-surface-strong text-fg-soft',
                                };
                                $sourceLabel = match ($report->finding) {
                                    'process_loss' => 'Process loss',
                                    'logging_gap' => 'Logging gap',
                                    'stock_shrinkage' => 'Stock shrinkage',
                                    default => 'Manual',
                                };
                                $subject = $report->batch_id !== null
                                    ? 'B-'.str_pad((string) $report->batch_id, 5, '0', STR_PAD_LEFT)
                                    : trim(($report->product?->unit_type ?? '').' '.($report->warehouse_location ? '@ '.$report->warehouse_location : ''));
                            @endphp
                            <tr wire:key="leakage-report-{{ $report->id }}" class="border-t border-border align-top text-[13px] text-fg-soft">
                                <td class="px-[18px] py-3">
                                    <span class="font-medium text-fg">{{ app(\App\Support\SettingsDate::class)->formatDate($report->report_date) }}</span>
                                    <span class="mt-0.5 block font-mono text-[11px] text-muted">{{ $subject }}{{ $report->product && $report->batch_id ? ' · '.$report->product->unit_type : '' }}</span>
                                </td>
                                <td class="px-[18px] py-3">
                                    <span class="whitespace-nowrap font-medium text-fg">{{ $sourceLabel }}</span>
                                    @if ($report->detected_by === 'anomaly')
                                        <span class="mt-1 block w-fit rounded-full bg-highlight-soft px-2 py-0.5 text-[10.5px] font-bold text-highlight-ink">Unusual</span>
                                    @endif
                                </td>
                                <td class="px-[18px] py-3 text-right tabular-nums">{{ $report->loss_percent === null ? '—' : number_format((float) $report->loss_percent, 2).'%' }}</td>
                                <td class="px-[18px] py-3 text-right tabular-nums text-fg-soft">{{ $report->z_score === null ? '—' : number_format((float) $report->z_score, 1) }}</td>
                                <td class="px-[18px] py-3"><span class="whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-bold {{ $flagClass }}">{{ $flag }}</span></td>
                                <td class="px-[18px] py-3">
                                    <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-bold {{ $statusClass }}">{{ ucfirst($report->status) }}</span>
                                    @if ($report->status === 'resolved' && $report->resolver)
                                        <span class="mt-1 block text-[11px] text-muted">by {{ $report->resolver->name }}</span>
                                    @endif
                                </td>
                                <td class="max-w-[420px] px-[18px] py-3 text-[12.5px] leading-5 text-fg-soft">
                                    {{ $report->explanation ?? '—' }}
                                    @if ($report->resolution_note)
                                        <span class="mt-1 block text-muted"><span class="font-semibold text-fg-soft">Note:</span> {{ $report->resolution_note }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-[18px] py-3 text-right">
                                    @if ($canWriteInventory && $report->status !== 'resolved')
                                        @if ($report->status === 'open')
                                            <button type="button" wire:click="openReviewModal({{ $report->id }}, 'acknowledge')" class="text-xs font-semibold text-accent-ink hover:text-accent-ink">Acknowledge</button>
                                            <span class="mx-1 text-subtle" aria-hidden="true">|</span>
                                        @endif
                                        <button type="button" wire:click="openReviewModal({{ $report->id }}, 'resolve')" class="text-xs font-semibold text-accent-ink hover:text-accent-ink">Resolve</button>
                                    @endif
                                    @if ($canSendRequests)
                                        <x-send-request-button related-type="leakage_report" :related-id="$report->id" compact label="Send request about leakage report #{{ $report->id }}" class="ml-2 align-middle" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><x-empty-state key="leakage" icon="beaker" :filtered="$leakageFlagFilter !== '' || $leakageSourceFilter !== '' || $leakageStatusFilter !== ''" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-border px-[18px] py-3">{{ $leakageReports->links() }}</div>
        </section>

        @if ($reviewingReport)
            <x-modal-dialog id="leakage-review" :title="$reviewAction === 'resolve' ? 'Resolve leakage report' : 'Acknowledge leakage report'" close="closeReviewModal">
                <p class="text-[13px] text-fg-soft">{{ $reviewingReport->explanation }}</p>
                <div>
                    <label for="leakage-review-note" class="mb-1.5 block text-xs font-semibold text-fg-soft">{{ $reviewAction === 'resolve' ? 'Resolution note' : 'Note (optional)' }}</label>
                    <textarea id="leakage-review-note" rows="3" wire:model="reviewNote" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg" placeholder="{{ $reviewAction === 'resolve' ? 'What was found and what was done' : 'Who is looking into it' }}"></textarea>
                    @error('reviewNote') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
                <x-slot:footer>
                    <button type="button" wire:click="closeReviewModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft">Cancel</button>
                    <button type="button" wire:click="saveReview" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">{{ $reviewAction === 'resolve' ? 'Resolve' : 'Acknowledge' }}</button>
                </x-slot:footer>
            </x-modal-dialog>
        @endif
    @else
        <section aria-labelledby="movement-list-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-[18px] py-3.5">
                <h2 id="movement-list-heading" class="text-sm font-bold text-fg">Stock movements</h2>
                <p class="mt-0.5 text-xs text-muted">A chronological audit trail of stock changes.</p>
            </div>
            <div class="flex flex-wrap items-end gap-3 border-b border-border bg-surface-muted px-[18px] py-3">
                <label class="flex flex-col gap-1 text-[11px] font-semibold text-fg-soft">Type
                    <select wire:model.live="movementTypeFilter" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
                        <option value="">All types</option>
                        @foreach (['production', 'sale', 'adjustment', 'leakage_writeoff', 'return', 'stock_in'] as $type)
                            <option value="{{ $type }}">{{ str_replace('_', ' ', ucfirst($type)) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-[11px] font-semibold text-fg-soft">Product
                    <select wire:model.live="movementProductFilter" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
                        <option value="">All products</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->unit_type }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-[11px] font-semibold text-fg-soft">From
                    <input type="date" wire:model.live="movementDateFrom" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
                </label>
                <label class="flex flex-col gap-1 text-[11px] font-semibold text-fg-soft">To
                    <input type="date" wire:model.live="movementDateTo" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
                </label>
                <span class="ml-auto text-xs text-muted">{{ $movementRows->total() }} movements</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] border-collapse text-left">
                    <thead class="bg-surface-muted">
                        <tr>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">When</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Type</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Product</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Change</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Level after</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Reference</th>
                            <th scope="col" class="px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Who</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($movementRows as $movement)
                            @php
                                $movementTypeClass = match ($movement->type) {
                                    'sale', 'leakage_writeoff' => 'bg-warning-soft text-warning-ink',
                                    'return', 'stock_in', 'production' => 'bg-success-soft text-success-ink',
                                    default => 'bg-accent-soft text-accent-ink',
                                };
                            @endphp
                            <tr wire:key="stock-movement-{{ $movement->id }}" class="border-t border-border text-[13px] text-fg-soft">
                                <td class="px-[18px] py-3 whitespace-nowrap">{{ app(\App\Support\SettingsDate::class)->formatDateTime($movement->created_at) }}</td>
                                <td class="px-[18px] py-3"><span class="rounded-full px-2.5 py-1 text-[11px] font-bold {{ $movementTypeClass }}">{{ str_replace('_', ' ', ucfirst($movement->type)) }}</span></td>
                                <td class="px-[18px] py-3"><span class="rounded bg-tag-soft px-2 py-1 text-[11px] font-bold text-tag-ink">{{ $movement->product?->unit_type ?? '—' }}</span></td>
                                <td class="px-[18px] py-3 font-mono font-semibold tabular-nums {{ $movement->quantity_change < 0 ? 'text-danger-ink' : 'text-success-ink' }}">{{ $movement->quantity_change > 0 ? '+' : '' }}{{ number_format($movement->quantity_change) }}</td>
                                <td class="px-[18px] py-3 font-mono tabular-nums">{{ number_format($movement->level_after) }}</td>
                                <td class="px-[18px] py-3">{{ $movement->reference ?: '—' }}</td>
                                <td class="px-[18px] py-3">{{ $movement->user?->name ?? 'System' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-empty-state key="movements" icon="arrows-right-left" :filtered="$movementTypeFilter !== '' || $movementProductFilter !== ''" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-border px-[18px] py-3">{{ $movementRows->links() }}</div>
        </section>
    @endif

    @if ($adjustmentOpen)
        @php
            $previewBefore = $selectedAdjustmentStock === null ? null : (float) $selectedAdjustmentStock->current_level;
            $previewAfter = $previewBefore === null || ! is_numeric($adjustmentChange)
                ? $previewBefore
                : $previewBefore + (int) $adjustmentChange;
        @endphp
        <div wire:click.self="closeAdjustmentModal" class="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-4">
            <section role="dialog" aria-modal="true" aria-labelledby="adjust-stock-heading" class="w-full max-w-[460px] overflow-hidden rounded-[10px] bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-border px-5 py-4">
                    <h2 id="adjust-stock-heading" class="text-[15px] font-bold text-fg">Adjust Stock</h2>
                    <button type="button" wire:click="closeAdjustmentModal" aria-label="Close adjustment dialog" class="rounded-md p-1 text-muted hover:bg-surface-strong hover:text-fg-soft"><x-heroicon-o-x-mark class="size-5" aria-hidden="true" /></button>
                </div>
                <div class="flex flex-col gap-3.5 px-5 py-[18px]">
                    <div>
                        <label for="adjustment-stock-line" class="mb-1.5 block text-xs font-semibold text-fg-soft">Stock line</label>
                        <select id="adjustment-stock-line" wire:model.live="adjustmentStockId" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                            <option value="">Select stock line…</option>
                            @foreach ($adjustableStockLines as $line)
                                <option value="{{ $line->id }}">B-{{ str_pad((string) $line->batch_id, 5, '0', STR_PAD_LEFT) }} · {{ $line->product->unit_type }} · {{ $line->warehouse_location ?: 'No location' }}</option>
                            @endforeach
                        </select>
                        @error('adjustmentStockId') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="adjustment-reason-type" class="mb-1.5 block text-xs font-semibold text-fg-soft">Reason type</label>
                            <select id="adjustment-reason-type" wire:model="adjustmentReasonType" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                                <option value="count_correction">Count correction</option>
                                <option value="damaged">Damaged</option>
                                <option value="leakage_writeoff">Leakage write-off</option>
                                <option value="customer_return">Customer return</option>
                            </select>
                            @error('adjustmentReasonType') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="adjustment-change" class="mb-1.5 block text-xs font-semibold text-fg-soft">Change (units)</label>
                            <input id="adjustment-change" type="number" step="1" wire:model.live="adjustmentChange" placeholder="e.g. -12" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                            @error('adjustmentChange') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <div class="flex items-center justify-between gap-2 rounded-md border border-border bg-surface-muted px-3 py-2.5 text-xs text-fg-soft">
                            <span>Level before and after</span>
                            <span class="inline-flex items-center gap-1.5 font-mono text-sm font-bold text-fg">
                                {{ $previewBefore === null ? '—' : number_format($previewBefore) }}
                                <x-heroicon-o-arrow-right class="size-4 text-muted" aria-label="to" />
                                {{ $previewAfter === null ? '—' : number_format($previewAfter) }}
                            </span>
                        </div>
                        <p class="mt-1 text-[11px] text-muted">Use a negative number to remove stock. Damaged and leakage write-offs must be negative; customer returns must be positive.</p>
                    </div>
                    <div>
                        <label for="adjustment-note" class="mb-1.5 block text-xs font-semibold text-fg-soft">Note (required)</label>
                        <textarea id="adjustment-note" rows="2" wire:model="adjustmentNote" placeholder="Explain the reason for this adjustment" class="w-full resize-y rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg placeholder:text-muted"></textarea>
                        @error('adjustmentNote') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-border bg-surface-muted px-5 py-3.5">
                    <button type="button" wire:click="closeAdjustmentModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Cancel</button>
                    <button type="button" wire:click="saveAdjustment" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60">Save Adjustment</button>
                </div>
            </section>
        </div>
    @endif

    @if ($leakageReportOpen)
        <div wire:click.self="closeLeakageReportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-4">
            <section role="dialog" aria-modal="true" aria-labelledby="leakage-report-heading" class="w-full max-w-[460px] overflow-hidden rounded-[10px] bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-border px-5 py-4">
                    <h2 id="leakage-report-heading" class="text-[15px] font-bold text-fg">New Leakage Report</h2>
                    <button type="button" wire:click="closeLeakageReportModal" aria-label="Close leakage report dialog" class="rounded-md p-1 text-muted hover:bg-surface-strong hover:text-fg-soft"><x-heroicon-o-x-mark class="size-5" aria-hidden="true" /></button>
                </div>
                <div class="flex flex-col gap-3.5 px-5 py-[18px]">
                    <div>
                        <label for="leakage-batch" class="mb-1.5 block text-xs font-semibold text-fg-soft">Production batch</label>
                        <select id="leakage-batch" wire:model="leakageBatchId" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                            <option value="">Select batch…</option>
                            @foreach ($batches as $batch)
                                <option value="{{ $batch->id }}">B-{{ str_pad((string) $batch->id, 5, '0', STR_PAD_LEFT) }} · {{ app(\App\Support\SettingsDate::class)->formatDate($batch->batch_date) }}</option>
                            @endforeach
                        </select>
                        @error('leakageBatchId') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="leakage-expected" class="mb-1.5 block text-xs font-semibold text-fg-soft">Expected volume (L)</label>
                            <input id="leakage-expected" type="number" min="0.01" step="0.01" wire:model="leakageExpectedVolume" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                            @error('leakageExpectedVolume') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="leakage-actual" class="mb-1.5 block text-xs font-semibold text-fg-soft">Actual volume (L)</label>
                            <input id="leakage-actual" type="number" min="0" step="0.01" wire:model="leakageActualVolume" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                            @error('leakageActualVolume') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="text-[11px] text-muted">Discrepancy and flag will be calculated when the report is saved.</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-border bg-surface-muted px-5 py-3.5">
                    <button type="button" wire:click="closeLeakageReportModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft">Cancel</button>
                    <button type="button" wire:click="saveLeakageReport" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">Save Report</button>
                </div>
            </section>
        </div>
    @endif

    @if ($stockInOpen)
        <div wire:click.self="closeStockInModal" class="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-4">
            <section role="dialog" aria-modal="true" aria-labelledby="stock-in-heading" class="w-full max-w-[460px] overflow-hidden rounded-[10px] bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-border px-5 py-4">
                    <h2 id="stock-in-heading" class="text-[15px] font-bold text-fg">Record Stock-In</h2>
                    <button type="button" wire:click="closeStockInModal" aria-label="Close stock-in dialog" class="rounded-md p-1 text-muted hover:bg-surface-strong hover:text-fg-soft"><x-heroicon-o-x-mark class="size-5" aria-hidden="true" /></button>
                </div>
                <div class="flex flex-col gap-3.5 px-5 py-[18px]">
                    <div>
                        <label for="stock-in-product" class="mb-1.5 block text-xs font-semibold text-fg-soft">Product</label>
                        <select id="stock-in-product" wire:model="stockInProductId" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                            <option value="">Select product…</option>
                            @foreach ($productCards as $card)
                                <option value="{{ $card['product']->id }}">{{ $card['product']->unit_type }} · {{ $card['product']->name }}</option>
                            @endforeach
                        </select>
                        @error('stockInProductId') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="stock-in-batch" class="mb-1.5 block text-xs font-semibold text-fg-soft">Batch</label>
                        <select id="stock-in-batch" wire:model="stockInBatchId" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                            <option value="">Select production batch…</option>
                            @foreach ($batches as $batch)
                                <option value="{{ $batch->id }}">B-{{ str_pad((string) $batch->id, 5, '0', STR_PAD_LEFT) }} · {{ app(\App\Support\SettingsDate::class)->formatDate($batch->batch_date) }}</option>
                            @endforeach
                        </select>
                        @error('stockInBatchId') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="stock-in-location" class="mb-1.5 block text-xs font-semibold text-fg-soft">Location</label>
                            <input id="stock-in-location" type="text" maxlength="255" wire:model="stockInLocation" placeholder="e.g. Bay A1" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg placeholder:text-muted">
                            @error('stockInLocation') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="stock-in-quantity" class="mb-1.5 block text-xs font-semibold text-fg-soft">Quantity (units)</label>
                            <input id="stock-in-quantity" type="number" min="1" step="1" wire:model="stockInQuantity" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                            @error('stockInQuantity') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-border bg-surface-muted px-5 py-3.5">
                    <button type="button" wire:click="closeStockInModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Cancel</button>
                    <button type="button" wire:click="saveStockIn" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60">Record Stock-In</button>
                </div>
            </section>
        </div>
    @endif

    @if ($stockCountOpen && isset($adjustableStockLines))
        @php
            $countLocations = $stockCountProductId === ''
                ? collect()
                : $adjustableStockLines->where('product_id', (int) $stockCountProductId)->pluck('warehouse_location')->unique()->values();
        @endphp
        <x-modal-dialog id="stock-count" title="Record stock count" close="closeStockCountModal">
            <p class="text-[13px] text-fg-soft">Counted stock replaces the system level. Any shortfall against what the recorded movements say should be there is checked for shrinkage.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="stock-count-product" class="mb-1.5 block text-xs font-semibold text-fg-soft">Product</label>
                    <select id="stock-count-product" wire:model.live="stockCountProductId" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                        <option value="">Select product…</option>
                        @foreach ($adjustableStockLines->pluck('product')->unique('id')->sortBy('unit_type') as $countProduct)
                            <option value="{{ $countProduct->id }}">{{ $countProduct->unit_type }} · {{ $countProduct->name }}</option>
                        @endforeach
                    </select>
                    @error('stockCountProductId') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="stock-count-location" class="mb-1.5 block text-xs font-semibold text-fg-soft">Location</label>
                    <select id="stock-count-location" wire:model="stockCountLocation" @disabled($countLocations->isEmpty()) class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg disabled:bg-surface-muted">
                        <option value="">{{ $countLocations->contains(null) || $countLocations->isEmpty() ? 'No location' : 'Select location…' }}</option>
                        @foreach ($countLocations->filter() as $countLocation)
                            <option value="{{ $countLocation }}">{{ $countLocation }}</option>
                        @endforeach
                    </select>
                    @error('stockCountLocation') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="stock-count-units" class="mb-1.5 block text-xs font-semibold text-fg-soft">Counted units</label>
                    <input id="stock-count-units" type="number" min="0" step="1" wire:model="stockCountUnits" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                    @error('stockCountUnits') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="stock-count-note" class="mb-1.5 block text-xs font-semibold text-fg-soft">Note</label>
                    <input id="stock-count-note" type="text" wire:model="stockCountNote" placeholder="Monthly count, cycle count…" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                    @error('stockCountNote') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
            </div>
            <x-slot:footer>
                <button type="button" wire:click="closeStockCountModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft">Cancel</button>
                <button type="button" wire:click="saveStockCount" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">Record count</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif
</div>
