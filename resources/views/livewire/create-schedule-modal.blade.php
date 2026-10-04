<div>
    @if ($isOpen)
    <div wire:click.self="closeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/45 p-4">
        <div role="dialog" aria-modal="true" aria-labelledby="create-schedule-heading" class="w-full max-w-[440px] overflow-hidden rounded-[10px] bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 id="create-schedule-heading" class="text-[15px] font-bold text-gray-900">Create Schedule</h3>
                <button type="button" wire:click="closeModal" aria-label="Close create schedule dialog" class="text-base text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <div class="px-5 py-[18px]">
                <div class="mb-3.5 grid grid-cols-2 gap-3">
                    <div>
                        <label for="create-date" class="mb-1.5 block text-xs font-semibold text-gray-600">Date</label>
                        <input id="create-date" type="date" wire:model.live="scheduledDate" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-[13px] text-gray-800">
                        @error('scheduledDate') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="create-time" class="mb-1.5 block text-xs font-semibold text-gray-600">Time</label>
                        <input id="create-time" type="time" wire:model="scheduledTime" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-[13px] text-gray-800">
                        @error('scheduledTime') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mb-3.5 grid grid-cols-2 gap-3">
                    <div>
                        <label for="create-sku" class="mb-1.5 block text-xs font-semibold text-gray-600">SKU</label>
                        <select id="create-sku" wire:model.live="productSku" class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-[13px] text-gray-800">
                            <option value="">Select SKU…</option>
                            @foreach ($skus as $sku)
                                <option value="{{ $sku }}">{{ $sku }}</option>
                            @endforeach
                        </select>
                        @error('productSku') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="create-line" class="mb-1.5 block text-xs font-semibold text-gray-600">Production line</label>
                        <select id="create-line" wire:model.live="productionLineId" class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-[13px] text-gray-800">
                            <option value="">Select line…</option>
                            @foreach ($lines as $line)
                                <option value="{{ $line->id }}">{{ $line->name }}</option>
                            @endforeach
                        </select>
                        @error('productionLineId') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mb-3.5">
                    <label for="create-target" class="mb-1.5 block text-xs font-semibold text-gray-600">Target units</label>
                    <input id="create-target" type="number" min="1" wire:model="targetUnits" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-[13px] text-gray-800">
                    @error('targetUnits') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                    @if ($forecastSuggestion !== null && $forecastSuggestion['available'])
                        <p class="mt-1 text-[11px] text-[#1d4ed8]">
                            ML suggestion: {{ number_format($forecastSuggestion['demand']) }} units for {{ $productSku }} on {{ $scheduledDate }}.
                            <button type="button" wire:click="applyForecast" class="font-semibold underline underline-offset-2 hover:text-blue-800">Apply suggestion</button>
                        </p>
                    @elseif ($forecastSuggestion !== null)
                        <p class="mt-1 text-[11px] text-amber-600">Forecast unavailable{{ $productSku !== '' ? " for {$productSku}" : '' }} — insufficient sales history, or the model is not yet trained / the service is unavailable. You can still create the schedule.</p>
                    @endif
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3.5">
                <button type="button" wire:click="closeModal" class="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="button" wire:click="saveSchedule" wire:loading.attr="disabled" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">Create Run</button>
            </div>
        </div>
    </div>
    @endif
</div>
