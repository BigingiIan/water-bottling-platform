<div>
    @if ($isOpen)
    <div wire:click.self="closeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/45 p-4">
        <div role="dialog" aria-modal="true" aria-labelledby="log-modal-heading" class="w-full max-w-[440px] overflow-hidden rounded-[10px] bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 id="log-modal-heading" class="text-[15px] font-bold text-gray-900">
                    Log Production{{ $schedule ? " — {$schedule->product_sku} · {$schedule->productionLine?->name}" : '' }}
                </h3>
                <button type="button" wire:click="closeModal" aria-label="Close log production dialog" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"><x-heroicon-o-x-mark class="size-5" aria-hidden="true" /></button>
            </div>
            <div class="px-5 py-[18px]">
                @if ($schedule === null)
                    <div class="mb-3.5">
                        <label for="log-schedule" class="mb-1.5 block text-xs font-semibold text-gray-600">Production run</label>
                        <select id="log-schedule" wire:model.live="scheduleId" class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-[13px] text-gray-800">
                            <option value="">Select run…</option>
                            @foreach ($openSchedules as $openSchedule)
                                <option value="{{ $openSchedule->id }}">
                                    {{ mb_substr((string) $openSchedule->scheduled_time, 0, 5) }} · {{ $openSchedule->product_sku }} · {{ $openSchedule->productionLine?->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('scheduleId') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div class="mb-3.5">
                    <label for="log-units" class="mb-1.5 block text-xs font-semibold text-gray-600">Units produced this entry</label>
                    <input id="log-units" type="number" min="1" wire:model="units" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-[13px] text-gray-800">
                    @if ($schedule !== null)
                        <p class="mt-1 text-[11px] text-[#1d4ed8]">Schedule target: {{ number_format((int) $schedule->target_units) }} units</p>
                    @endif
                    @error('units') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="mb-3.5">
                    <label for="log-quality" class="mb-1.5 block text-xs font-semibold text-gray-600">Quality check</label>
                    <select id="log-quality" wire:model="qualityStatus" class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-[13px] text-gray-800">
                        <option value="passed">Passed</option>
                        <option value="flagged">Flagged for review</option>
                    </select>
                    @error('qualityStatus') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="log-notes" class="mb-1.5 block text-xs font-semibold text-gray-600">Notes (optional)</label>
                    <input id="log-notes" wire:model="notes" placeholder="e.g. minor label misalignment on batch" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-[13px] text-gray-800 placeholder:text-gray-400">
                    @error('notes') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3.5">
                <button type="button" wire:click="closeModal" class="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="button" wire:click="saveLog" wire:loading.attr="disabled" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">Save Entry</button>
            </div>
        </div>
    </div>
    @endif
</div>
