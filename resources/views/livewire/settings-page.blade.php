<div class="flex flex-col gap-6" x-data="{ toast: false, toastMessage: '' }" x-on:settings-saved.window="toastMessage = $event.detail.message; toast = true; setTimeout(() => toast = false, 4000)">
    <div x-cloak x-show="toast" x-transition role="status" class="fixed right-4 top-4 z-40 rounded-md border border-success/35 bg-success-soft px-4 py-2.5 text-sm font-medium text-success-ink shadow-lg">
        <span x-text="toastMessage"></span>
    </div>

    <x-page-header help="settings" title="Settings" subtitle="Manage organisation defaults, operations, notifications, and security." />

    <nav aria-label="Settings sections" class="flex flex-wrap gap-1 border-b border-border">
        @foreach ([
            'general' => 'General',
            'appearance' => 'Appearance',
            'operations' => 'Operations',
            'forecasting' => 'Forecasting',
            'invoicing' => 'Invoicing',
            'notifications' => 'Notifications',
            'security' => 'Security',
            'audit' => 'Audit trail',
        ] as $tab => $label)
            <button
                type="button"
                wire:click="$set('activeTab', '{{ $tab }}')"
                @class([
                    'border-b-2 px-3 py-2.5 text-[13px] font-semibold',
                    'border-accent text-accent-ink' => $activeTab === $tab,
                    'border-transparent text-muted hover:text-fg' => $activeTab !== $tab,
                ])
                @if ($activeTab === $tab) aria-current="page" @endif
            >{{ $label }}</button>
        @endforeach
    </nav>

    @if ($activeTab === 'audit')
        <section aria-labelledby="settings-audit-heading" class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <div class="border-b border-border px-[18px] py-4">
                <h2 id="settings-audit-heading" class="text-[15px] font-bold text-fg">Audit trail</h2>
                <p class="mt-0.5 text-xs text-muted">System activity, newest first.</p>
            </div>
            <div class="flex flex-wrap gap-2 border-b border-border bg-surface-muted px-[18px] py-3">
                <label class="sr-only" for="audit-user-filter">Filter by user</label>
                <select id="audit-user-filter" wire:model.live="auditUser" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
                    <option value="">All users</option>
                    @foreach ($auditUsers as $auditUserOption)
                        <option value="{{ $auditUserOption->id }}">{{ $auditUserOption->name }}</option>
                    @endforeach
                </select>
                <label class="sr-only" for="audit-action-filter">Filter by action</label>
                <select id="audit-action-filter" wire:model.live="auditAction" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
                    <option value="">All actions</option>
                    @foreach ($auditActions as $action)
                        <option value="{{ $action }}">{{ $action }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-1.5 text-xs text-muted">
                    From
                    <input type="date" wire:model.live="auditDateFrom" aria-label="Audit start date" class="rounded-md border border-border-strong bg-surface px-2 py-1.5 text-xs text-fg-soft">
                </label>
                <label class="flex items-center gap-1.5 text-xs text-muted">
                    To
                    <input type="date" wire:model.live="auditDateTo" aria-label="Audit end date" class="rounded-md border border-border-strong bg-surface px-2 py-1.5 text-xs text-fg-soft">
                </label>
            </div>
            @error('auditDateTo')
                <p role="alert" class="border-b border-danger/20 bg-danger-soft px-[18px] py-2 text-xs text-danger-ink">{{ $message }}</p>
            @enderror
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] border-collapse text-left">
                    <caption class="sr-only">Audit trail entries with date, user, action, and table.</caption>
                    <thead class="bg-surface-muted">
                        <tr>
                            <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">When</th>
                            <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">User</th>
                            <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Action</th>
                            <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Table</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($auditRows as $auditRow)
                            <tr wire:key="audit-row-{{ $auditRow->id }}" class="border-b border-border hover:bg-surface-muted">
                                <td class="whitespace-nowrap px-[18px] py-3 text-sm text-fg-soft">{{ app(\App\Support\SettingsDate::class)->formatDateTime($auditRow->timestamp, 'H:i:s') }}</td>
                                <td class="px-[18px] py-3 text-sm text-fg-soft">{{ $auditRow->user?->name ?? 'System' }}</td>
                                <td class="px-[18px] py-3 font-mono text-xs text-fg-soft">{{ $auditRow->action }}</td>
                                <td class="px-[18px] py-3 text-sm text-fg-soft">{{ $auditRow->table_name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-[18px] py-8 text-center text-sm text-muted">No audit entries match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($auditRows->hasPages())
                <div class="border-t border-border px-[18px] py-3">{{ $auditRows->links() }}</div>
            @endif
        </section>
    @else
        <section aria-labelledby="settings-panel-heading" class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <div class="border-b border-border px-[18px] py-4">
                <h2 id="settings-panel-heading" class="text-[15px] font-bold text-fg">{{ str($activeTab)->headline() }}</h2>
                <p class="mt-0.5 text-xs text-muted">Settings are stored as overrides; defaults are restored when an override is reset.</p>
            </div>

            @if (! $canWrite)
                <p role="status" class="border-b border-info/20 bg-info-soft px-[18px] py-2.5 text-sm text-info-ink">View-only access. You cannot change these settings.</p>
            @endif

            @if ($activeTab === 'forecasting')
                <div class="grid gap-3 border-b border-border bg-surface-muted p-[18px] sm:grid-cols-2">
                    <div class="rounded-md border border-border bg-surface px-3.5 py-3">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-muted">Current model version</p>
                        <p class="mt-1 font-mono text-sm text-fg">{{ $modelVersion }}</p>
                    </div>
                    <div class="rounded-md border border-border bg-surface px-3.5 py-3">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-muted">Last forecast run</p>
                        <p class="mt-1 text-sm text-fg">{{ app(\App\Support\SettingsDate::class)->formatDateTime($lastForecastRunAt, 'H:i:s') }}</p>
                    </div>
                </div>
            @endif

            <form wire:submit="saveSettings">
                <div class="divide-y divide-border">
                    @foreach ($definitions as $key => $definition)
                        @php
                            $field = $definition['field'];
                            $fieldValue = $values[$field] ?? $definition['default'];
                            $readonly = ($definition['readonly'] ?? false) === true;
                        @endphp
                        <div class="flex flex-col gap-3 px-[18px] py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0 sm:max-w-[52%]">
                                <label @if ($definition['input'] !== 'readonly') for="setting-{{ $field }}" @endif class="block text-[13px] font-semibold text-fg">{{ $definition['label'] }}</label>
                                @if (isset($definition['description']))
                                    <p class="mt-1 text-xs text-muted">{{ $definition['description'] }}</p>
                                @endif
                            </div>
                            <div class="w-full sm:max-w-sm">
                                @if ($definition['input'] === 'checkbox')
                                    <label class="inline-flex items-center gap-2.5">
                                        <input
                                            id="setting-{{ $field }}"
                                            type="checkbox"
                                            wire:model="values.{{ $field }}"
                                            @checked((bool) $fieldValue)
                                            @disabled(! $canWrite)
                                            class="size-4 rounded border-border-strong text-accent-ink focus:ring-focus disabled:opacity-60"
                                        >
                                        <span class="text-sm text-fg-soft">{{ $fieldValue ? 'Enabled' : 'Disabled' }}</span>
                                    </label>
                                @elseif ($definition['input'] === 'select')
                                    <select
                                        id="setting-{{ $field }}"
                                        wire:model="values.{{ $field }}"
                                        @disabled(! $canWrite)
                                        class="w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-fg disabled:bg-surface-muted disabled:text-muted"
                                    >
                                        @foreach (array_is_list($definition['options']) ? array_combine($definition['options'], $definition['options']) : $definition['options'] as $optionValue => $optionLabel)
                                            <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($definition['input'] === 'logo')
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span class="flex size-12 items-center justify-center rounded-md border border-border bg-surface-muted">
                                            @if ($logoUpload && ! $errors->has('logoUpload'))
                                                <img src="{{ $logoUpload->temporaryUrl() }}" alt="New logo preview" class="size-10 rounded object-contain">
                                            @elseif ($logoUrl)
                                                <img src="{{ $logoUrl }}" alt="Current logo" class="size-10 rounded object-contain">
                                            @else
                                                <x-brand-mark size="size-9" />
                                            @endif
                                        </span>
                                        @if ($canWrite)
                                            <div class="flex min-w-0 flex-1 flex-col gap-2">
                                                <input
                                                    id="setting-{{ $field }}"
                                                    type="file"
                                                    accept="image/png,image/jpeg,image/webp"
                                                    wire:model="logoUpload"
                                                    class="block w-full text-sm text-fg-soft file:mr-3 file:rounded-md file:border-0 file:bg-accent-soft file:px-3 file:py-1.5 file:text-[13px] file:font-semibold file:text-accent-ink hover:file:bg-accent-soft"
                                                >
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" wire:click="saveLogo" wire:loading.attr="disabled" @disabled(! $logoUpload) class="rounded-md bg-accent px-3 py-1.5 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-50">Upload logo</button>
                                                    @if ($logoUrl)
                                                        <button type="button" wire:click="removeLogo" wire:loading.attr="disabled" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Remove</button>
                                                    @endif
                                                </div>
                                            </div>
                                        @else
                                            <p class="text-sm text-fg-soft">{{ $logoUrl ? 'Custom logo' : 'Default AquaOps mark' }}</p>
                                        @endif
                                    </div>
                                    @error('logoUpload')
                                        <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>
                                    @enderror
                                @elseif ($readonly)
                                    <p class="rounded-md border border-border bg-surface-muted px-3 py-2 text-sm text-fg-soft">{{ $fieldValue }}</p>
                                @else
                                    <input
                                        id="setting-{{ $field }}"
                                        type="{{ $definition['input'] }}"
                                        @if (isset($definition['step'])) step="{{ $definition['step'] }}" @endif
                                        wire:model="values.{{ $field }}"
                                        @disabled(! $canWrite)
                                        class="w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-fg disabled:bg-surface-muted disabled:text-muted"
                                    >
                                @endif
                                @error('values.'.$field)
                                    <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($canWrite)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-surface-muted px-[18px] py-3.5">
                        <button type="button" wire:click="requestResetDefaults" class="text-sm font-semibold text-accent-ink hover:text-accent-ink hover:underline">Reset to defaults</button>
                        <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">Save settings</button>
                    </div>
                @endif
            </form>
        </section>

        @if ($activeTab === 'appearance')
            <section aria-labelledby="appearance-preview-heading" class="rounded-lg border border-border bg-surface p-[18px] shadow-sm">
                <h2 id="appearance-preview-heading" class="text-[15px] font-bold text-fg">Colour profiles</h2>
                <p class="mt-0.5 text-xs text-muted">Each profile in light and dark. Status colours keep their meaning in every profile.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    @foreach ($previewProfiles as $previewProfile => $previewLabel)
                        <div class="grid min-w-0 gap-2">
                            <x-appearance-preview :profile="$previewProfile" :label="$previewLabel" mode="light" />
                            <x-appearance-preview :profile="$previewProfile" :label="$previewLabel" mode="dark" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endif

    @if ($showResetModal)
        <div
            class="fixed inset-0 z-30 flex items-center justify-center bg-overlay p-4"
            role="presentation"
            x-data
            x-init="$nextTick(() => $refs.cancelButton.focus())"
            x-on:keydown.escape.window="$wire.closeResetModal()"
        >
            <section role="dialog" aria-modal="true" aria-labelledby="reset-settings-heading" class="w-full max-w-md rounded-lg bg-surface p-5 shadow-xl">
                <h2 id="reset-settings-heading" class="text-base font-bold text-fg">Reset {{ str($resetTab)->headline() }} settings?</h2>
                <p class="mt-2 text-sm text-fg-soft">All overrides in this tab will be removed and the configured defaults restored.</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button x-ref="cancelButton" type="button" wire:click="closeResetModal" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-medium text-fg-soft hover:bg-surface-muted">Cancel</button>
                    <button type="button" wire:click="resetToDefaults" wire:loading.attr="disabled" class="rounded-md bg-danger px-3 py-2 text-sm font-semibold text-on-danger hover:bg-danger-hover disabled:opacity-60">Reset to defaults</button>
                </div>
            </section>
        </div>
    @endif
</div>
