<div>
    @if ($isOpen)
    <div wire:click.self="closeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-4">
        <div role="dialog" aria-modal="true" aria-labelledby="log-modal-heading" class="w-full max-w-[440px] overflow-hidden rounded-[10px] bg-surface shadow-xl">
            <div class="flex items-center justify-between border-b border-border px-5 py-4">
                <h3 id="log-modal-heading" class="text-[15px] font-bold text-fg">
                    Log Production{{ $schedule ? " — {$schedule->product_sku} · {$schedule->productionLine?->name}" : '' }}
                </h3>
                <button type="button" wire:click="closeModal" aria-label="Close log production dialog" class="rounded-md p-1 text-muted hover:bg-surface-strong hover:text-fg-soft"><x-heroicon-o-x-mark class="size-5" aria-hidden="true" /></button>
            </div>
            <div class="px-5 py-[18px]">
                @if ($schedule === null)
                    <div class="mb-3.5">
                        <label for="log-schedule" class="mb-1.5 block text-xs font-semibold text-fg-soft">Production run</label>
                        <select id="log-schedule" wire:model.live="scheduleId" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                            <option value="">Select run…</option>
                            @foreach ($openSchedules as $openSchedule)
                                <option value="{{ $openSchedule->id }}">
                                    {{ mb_substr((string) $openSchedule->scheduled_time, 0, 5) }} · {{ $openSchedule->product_sku }} · {{ $openSchedule->productionLine?->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('scheduleId') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                    </div>
                @else
                    @error('scheduleId') <p role="alert" class="mb-3.5 rounded-md border border-danger/35 bg-danger-soft px-3 py-2 text-xs font-medium text-danger-ink">{{ $message }}</p> @enderror
                @endif
                <div class="mb-3.5">
                    <label for="log-units" class="mb-1.5 block text-xs font-semibold text-fg-soft">Units produced this entry</label>
                    <input id="log-units" type="number" min="1" wire:model="units" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                    @if ($schedule !== null)
                        <p class="mt-1 text-[11px] text-accent-ink">Schedule target: {{ number_format((int) $schedule->target_units) }} units</p>
                    @endif
                    @error('units') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="mb-3.5">
                    <label for="log-quality" class="mb-1.5 block text-xs font-semibold text-fg-soft">Quality check</label>
                    <select id="log-quality" wire:model="qualityStatus" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                        <option value="passed">Passed</option>
                        <option value="flagged">Flagged for review</option>
                    </select>
                    @error('qualityStatus') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="log-notes" class="mb-1.5 block text-xs font-semibold text-fg-soft">Notes (optional)</label>
                    <input id="log-notes" wire:model="notes" placeholder="e.g. minor label misalignment on batch" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg placeholder:text-muted">
                    @error('notes') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-border bg-surface-muted px-5 py-3.5">
                <button type="button" wire:click="closeModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Cancel</button>
                <button type="button" wire:click="saveLog" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60">Save Entry</button>
            </div>
        </div>
    </div>
    @endif
</div>
