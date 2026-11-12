<div>
    @if ($isOpen)
    <div wire:click.self="closeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-4">
        <div role="dialog" aria-modal="true" aria-labelledby="create-schedule-heading" class="w-full max-w-[440px] overflow-hidden rounded-[10px] bg-surface shadow-xl">
            <div class="flex items-center justify-between border-b border-border px-5 py-4">
                <h3 id="create-schedule-heading" class="text-[15px] font-bold text-fg">Create Schedule</h3>
                <button type="button" wire:click="closeModal" aria-label="Close create schedule dialog" class="rounded-md p-1 text-muted hover:bg-surface-strong hover:text-fg-soft"><x-heroicon-o-x-mark class="size-5" aria-hidden="true" /></button>
            </div>
            <div class="px-5 py-[18px]">
                <div class="mb-3.5 grid grid-cols-2 gap-3">
                    <div>
                        <label for="create-date" class="mb-1.5 block text-xs font-semibold text-fg-soft">Date</label>
                        <input id="create-date" type="date" wire:model.live="scheduledDate" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                        @error('scheduledDate') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="create-time" class="mb-1.5 block text-xs font-semibold text-fg-soft">Time</label>
                        <input id="create-time" type="time" wire:model="scheduledTime" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                        @error('scheduledTime') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mb-3.5 grid grid-cols-2 gap-3">
                    <div>
                        <label for="create-sku" class="mb-1.5 block text-xs font-semibold text-fg-soft">SKU</label>
                        <select id="create-sku" wire:model.live="productSku" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                            <option value="">Select SKU…</option>
                            @foreach ($skus as $sku)
                                <option value="{{ $sku }}">{{ $sku }}</option>
                            @endforeach
                        </select>
                        @error('productSku') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="create-line" class="mb-1.5 block text-xs font-semibold text-fg-soft">Production line</label>
                        <select id="create-line" wire:model.live="productionLineId" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                            <option value="">Select line…</option>
                            @foreach ($lines as $line)
                                <option value="{{ $line->id }}">{{ $line->name }}</option>
                            @endforeach
                        </select>
                        @error('productionLineId') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
                @if ($lineWarning)
                    <p role="alert" class="mb-3.5 flex items-start gap-2 rounded-md border border-warning/35 bg-warning-soft px-3 py-2 text-[12px] text-warning-ink">
                        <x-heroicon-o-wrench-screwdriver class="mt-px size-4 shrink-0" aria-hidden="true" />
                        {{ $lineWarning }}
                    </p>
                @endif
                <div class="mb-3.5">
                    <label for="create-target" class="mb-1.5 block text-xs font-semibold text-fg-soft">Target units</label>
                    <input id="create-target" type="number" min="1" wire:model="targetUnits" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                    @error('targetUnits') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                    @if ($forecastSuggestion !== null && $forecastSuggestion['available'])
                        <p class="mt-1 text-[11px] text-accent-ink">
                            ML suggestion: {{ number_format($forecastSuggestion['demand']) }} units for {{ $productSku }} on {{ app(\App\Support\SettingsDate::class)->formatDate($scheduledDate) }}.
                            <button type="button" wire:click="applyForecast" class="font-semibold underline underline-offset-2 hover:text-accent-ink">Apply suggestion</button>
                            <span aria-hidden="true">·</span>
                            <button type="button" wire:click="requestForecast" wire:loading.attr="disabled" wire:target="requestForecast" class="font-semibold underline underline-offset-2 hover:text-accent-ink disabled:opacity-60">Refresh</button>
                        </p>
                    @elseif ($forecastSuggestion !== null && ! $forecastSuggestion['requested'])
                        <p class="mt-1 text-[11px] text-muted">
                            No forecast stored yet{{ $productSku !== '' ? " for {$productSku}" : '' }} on this date.
                            <button type="button" wire:click="requestForecast" wire:loading.attr="disabled" wire:target="requestForecast" class="font-semibold text-accent-ink underline underline-offset-2 disabled:opacity-60">Get ML suggestion</button>
                            <span wire:loading wire:target="requestForecast">Asking the forecast service…</span>
                        </p>
                    @elseif ($forecastSuggestion !== null)
                        <p class="mt-1 text-[11px] text-warning-ink">Forecast unavailable{{ $productSku !== '' ? " for {$productSku}" : '' }} — insufficient sales history, or the model is not yet trained / the service is unavailable. You can still create the schedule.</p>
                    @endif
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-border bg-surface-muted px-5 py-3.5">
                <button type="button" wire:click="closeModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Cancel</button>
                <button type="button" wire:click="saveSchedule" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60">Create Run</button>
            </div>
        </div>
    </div>
    @endif
</div>
