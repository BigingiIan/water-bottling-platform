<div class="flex flex-col gap-6" x-data="{ toast: false, toastMessage: '' }" x-on:settings-saved.window="toastMessage = $event.detail.message; toast = true; setTimeout(() => toast = false, 4000)">
    <div x-cloak x-show="toast" x-transition role="status" class="fixed right-4 top-4 z-40 rounded-md border border-green-200 bg-green-50 px-4 py-2.5 text-sm font-medium text-green-800 shadow-lg">
        <span x-text="toastMessage"></span>
    </div>

    <x-page-header title="Settings" subtitle="Manage organisation defaults, operations, notifications, and security." />

    <nav aria-label="Settings sections" class="flex flex-wrap gap-1 border-b border-gray-200">
        @foreach ([
            'general' => 'General',
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
                    'border-blue-600 text-blue-700' => $activeTab === $tab,
                    'border-transparent text-gray-500 hover:text-gray-800' => $activeTab !== $tab,
                ])
                @if ($activeTab === $tab) aria-current="page" @endif
            >{{ $label }}</button>
        @endforeach
    </nav>

    @if ($activeTab === 'audit')
        <section aria-labelledby="settings-audit-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-[18px] py-4">
                <h2 id="settings-audit-heading" class="text-[15px] font-bold text-gray-900">Audit trail</h2>
                <p class="mt-0.5 text-xs text-gray-500">System activity, newest first.</p>
            </div>
            <div class="flex flex-wrap gap-2 border-b border-gray-100 bg-gray-50 px-[18px] py-3">
                <label class="sr-only" for="audit-user-filter">Filter by user</label>
                <select id="audit-user-filter" wire:model.live="auditUser" class="rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700">
                    <option value="">All users</option>
                    @foreach ($auditUsers as $auditUserOption)
                        <option value="{{ $auditUserOption->id }}">{{ $auditUserOption->name }}</option>
                    @endforeach
                </select>
                <label class="sr-only" for="audit-action-filter">Filter by action</label>
                <select id="audit-action-filter" wire:model.live="auditAction" class="rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700">
                    <option value="">All actions</option>
                    @foreach ($auditActions as $action)
                        <option value="{{ $action }}">{{ $action }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-1.5 text-xs text-gray-500">
                    From
                    <input type="date" wire:model.live="auditDateFrom" aria-label="Audit start date" class="rounded-md border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-700">
                </label>
                <label class="flex items-center gap-1.5 text-xs text-gray-500">
                    To
                    <input type="date" wire:model.live="auditDateTo" aria-label="Audit end date" class="rounded-md border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-700">
                </label>
            </div>
            @error('auditDateTo')
                <p role="alert" class="border-b border-red-100 bg-red-50 px-[18px] py-2 text-xs text-red-700">{{ $message }}</p>
            @enderror
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] border-collapse text-left">
                    <caption class="sr-only">Audit trail entries with date, user, action, and table.</caption>
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">When</th>
                            <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">User</th>
                            <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Action</th>
                            <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Table</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($auditRows as $auditRow)
                            <tr wire:key="audit-row-{{ $auditRow->id }}" class="border-b border-gray-100 hover:bg-gray-50">
                                <td class="whitespace-nowrap px-[18px] py-3 text-sm text-gray-600">{{ app(\App\Support\SettingsDate::class)->formatDateTime($auditRow->timestamp, 'H:i:s') }}</td>
                                <td class="px-[18px] py-3 text-sm text-gray-700">{{ $auditRow->user?->name ?? 'System' }}</td>
                                <td class="px-[18px] py-3 font-mono text-xs text-gray-700">{{ $auditRow->action }}</td>
                                <td class="px-[18px] py-3 text-sm text-gray-700">{{ $auditRow->table_name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-[18px] py-8 text-center text-sm text-gray-500">No audit entries match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($auditRows->hasPages())
                <div class="border-t border-gray-100 px-[18px] py-3">{{ $auditRows->links() }}</div>
            @endif
        </section>
    @else
        <section aria-labelledby="settings-panel-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-[18px] py-4">
                <h2 id="settings-panel-heading" class="text-[15px] font-bold text-gray-900">{{ str($activeTab)->headline() }}</h2>
                <p class="mt-0.5 text-xs text-gray-500">Settings are stored as overrides; defaults are restored when an override is reset.</p>
            </div>

            @if (! $canWrite)
                <p role="status" class="border-b border-blue-100 bg-blue-50 px-[18px] py-2.5 text-sm text-blue-800">View-only access. You cannot change these settings.</p>
            @endif

            @if ($activeTab === 'forecasting')
                <div class="grid gap-3 border-b border-gray-100 bg-gray-50 p-[18px] sm:grid-cols-2">
                    <div class="rounded-md border border-gray-200 bg-white px-3.5 py-3">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Current model version</p>
                        <p class="mt-1 font-mono text-sm text-gray-800">{{ $modelVersion }}</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-white px-3.5 py-3">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Last forecast run</p>
                        <p class="mt-1 text-sm text-gray-800">{{ app(\App\Support\SettingsDate::class)->formatDateTime($lastForecastRunAt, 'H:i:s') }}</p>
                    </div>
                </div>
            @endif

            <form wire:submit="saveSettings">
                <div class="divide-y divide-gray-100">
                    @foreach ($definitions as $key => $definition)
                        @php
                            $field = $definition['field'];
                            $fieldValue = $values[$field] ?? $definition['default'];
                            $readonly = ($definition['readonly'] ?? false) === true;
                        @endphp
                        <div class="flex flex-col gap-3 px-[18px] py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0 sm:max-w-[52%]">
                                <label @if ($definition['input'] !== 'readonly') for="setting-{{ $field }}" @endif class="block text-[13px] font-semibold text-gray-800">{{ $definition['label'] }}</label>
                                @if (isset($definition['description']))
                                    <p class="mt-1 text-xs text-gray-500">{{ $definition['description'] }}</p>
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
                                            class="size-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:opacity-60"
                                        >
                                        <span class="text-sm text-gray-700">{{ $fieldValue ? 'Enabled' : 'Disabled' }}</span>
                                    </label>
                                @elseif ($definition['input'] === 'select')
                                    <select
                                        id="setting-{{ $field }}"
                                        wire:model="values.{{ $field }}"
                                        @disabled(! $canWrite)
                                        class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 disabled:bg-gray-50 disabled:text-gray-500"
                                    >
                                        @foreach ($definition['options'] as $option)
                                            <option value="{{ $option }}">{{ $option }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($readonly)
                                    <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">{{ $fieldValue }}</p>
                                @else
                                    <input
                                        id="setting-{{ $field }}"
                                        type="{{ $definition['input'] }}"
                                        @if (isset($definition['step'])) step="{{ $definition['step'] }}" @endif
                                        wire:model="values.{{ $field }}"
                                        @disabled(! $canWrite)
                                        class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 disabled:bg-gray-50 disabled:text-gray-500"
                                    >
                                @endif
                                @error('values.'.$field)
                                    <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($canWrite)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 px-[18px] py-3.5">
                        <button type="button" wire:click="requestResetDefaults" class="text-sm font-semibold text-blue-700 hover:text-blue-900 hover:underline">Reset to defaults</button>
                        <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:opacity-60">Save settings</button>
                    </div>
                @endif
            </form>
        </section>
    @endif

    @if ($showResetModal)
        <div
            class="fixed inset-0 z-30 flex items-center justify-center bg-gray-950/40 p-4"
            role="presentation"
            x-data
            x-init="$nextTick(() => $refs.cancelButton.focus())"
            x-on:keydown.escape.window="$wire.closeResetModal()"
        >
            <section role="dialog" aria-modal="true" aria-labelledby="reset-settings-heading" class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h2 id="reset-settings-heading" class="text-base font-bold text-gray-900">Reset {{ str($resetTab)->headline() }} settings?</h2>
                <p class="mt-2 text-sm text-gray-600">All overrides in this tab will be removed and the configured defaults restored.</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button x-ref="cancelButton" type="button" wire:click="closeResetModal" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="button" wire:click="resetToDefaults" wire:loading.attr="disabled" class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60">Reset to defaults</button>
                </div>
            </section>
        </div>
    @endif
</div>
