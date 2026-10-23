<div class="flex flex-col gap-6">
    <x-page-header heading-id="error-logs-heading" title="Error Logs" :subtitle="'Branded 403 and 500 errors from the last '.\App\Support\Plural::count((int) app(\App\Services\SettingsService::class)->get('retention.error_log_days', 30), 'day').'.'">
        <x-slot:actions>
            <div class="inline-flex rounded-md border border-gray-300 bg-white p-0.5 text-xs">
                <button type="button" wire:click="showGroups" @class(['rounded px-2.5 py-1.5', 'bg-blue-600 text-white' => $viewMode === 'groups', 'text-gray-600 hover:bg-gray-100' => $viewMode !== 'groups']) aria-pressed="{{ $viewMode === 'groups' ? 'true' : 'false' }}">Grouped</button>
                <button type="button" wire:click="showEvents" @class(['rounded px-2.5 py-1.5', 'bg-blue-600 text-white' => $viewMode === 'events', 'text-gray-600 hover:bg-gray-100' => $viewMode !== 'events']) aria-pressed="{{ $viewMode === 'events' ? 'true' : 'false' }}">Raw events</button>
            </div>
            <label class="sr-only" for="error-reference-search">Search reference code</label>
            <input
                id="error-reference-search"
                type="search"
                wire:model.live.debounce.300ms="referenceSearch"
                maxlength="9"
                placeholder="Search reference code…"
                class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 placeholder:text-gray-400 sm:w-64"
            >
        </x-slot:actions>
    </x-page-header>

    <section aria-labelledby="error-logs-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">

    <div class="flex flex-wrap gap-2 border-b border-gray-100 bg-gray-50 px-[18px] py-3">
        <select wire:model.live="statusFilter" aria-label="Filter by status" class="rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700">
            <option value="">All statuses</option>
            <option value="403">403 — Forbidden</option>
            <option value="500">500 — Server error</option>
        </select>
        <select wire:model.live="userFilter" aria-label="Filter by user" class="max-w-56 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700">
            <option value="">All users</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
            @endforeach
        </select>
        <select wire:model.live="moduleFilter" aria-label="Filter by module" class="rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700">
            <option value="">All modules</option>
            @foreach ($modules as $module)
                <option value="{{ $module->slug }}">{{ $module->name }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-1.5 text-xs text-gray-500">
            From
            <input type="date" wire:model.live="startDate" aria-label="Start date" class="rounded-md border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-700">
        </label>
        <label class="flex items-center gap-1.5 text-xs text-gray-500">
            To
            <input type="date" wire:model.live="endDate" aria-label="End date" class="rounded-md border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-700">
        </label>
    </div>

    <div class="overflow-x-auto">
        @if ($viewMode === 'groups')
            <table class="w-full min-w-[760px] border-collapse text-left">
                <caption class="sr-only">Error groups ordered by most recent occurrence.</caption>
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Exception class</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Count</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">First seen</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($groups as $group)
                        <tr wire:key="error-group-{{ $group->fingerprint }}" class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-[18px] py-3 font-mono text-xs text-gray-700">
                                <button type="button" wire:click="showGroupDetails('{{ $group->fingerprint }}')" class="break-all text-left font-semibold text-blue-700 hover:text-blue-900 hover:underline">
                                    {{ $group->exception_class ?? 'Unknown exception' }}
                                </button>
                            </td>
                            <td class="px-[18px] py-3 text-sm text-gray-700">{{ $group->event_count }}</td>
                            <td class="px-[18px] py-3 text-sm text-gray-600">{{ app(\App\Support\SettingsDate::class)->formatDateTime($group->first_seen) }}</td>
                            <td class="px-[18px] py-3 text-sm text-gray-600">{{ app(\App\Support\SettingsDate::class)->formatDateTime($group->last_seen) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-[18px] py-8 text-center text-sm text-gray-500">No fingerprinted error groups match these filters. Older events remain available in the raw event list.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="w-full min-w-[960px] border-collapse text-left">
                <caption class="sr-only">Recorded branded application errors, newest first. Select a reference to view event details.</caption>
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Reference</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Status</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Path</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Method</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">User</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Module</th>
                        @if ($canViewSensitiveDetails)
                            <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Resolution</th>
                        @endif
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr wire:key="error-event-{{ $event->id }}" class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-[18px] py-3 font-mono text-xs">
                                <button
                                    type="button"
                                    wire:click="showDetails({{ $event->id }})"
                                    class="font-semibold text-blue-700 hover:text-blue-900 hover:underline"
                                >
                                    {{ $event->reference }}
                                </button>
                            </td>
                            <td class="px-[18px] py-3 text-sm">
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                    'bg-amber-100 text-amber-800' => $event->status_code === 403,
                                    'bg-red-100 text-red-800' => $event->status_code === 500,
                                ])>{{ $event->status_code }}</span>
                            </td>
                            <td class="max-w-64 truncate px-[18px] py-3 font-mono text-xs text-gray-700">{{ $event->path }}</td>
                            <td class="px-[18px] py-3 text-sm text-gray-700">{{ $event->method }}</td>
                            <td class="px-[18px] py-3 text-sm text-gray-700">{{ $event->user?->name ?? 'Guest' }}</td>
                            <td class="px-[18px] py-3 text-sm text-gray-700">{{ $event->module_slug ? str($event->module_slug)->headline() : '—' }}</td>
                            @if ($canViewSensitiveDetails)
                                <td class="px-[18px] py-3 text-sm">
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                        'bg-green-100 text-green-800' => $event->resolved_at !== null,
                                        'bg-gray-100 text-gray-700' => $event->resolved_at === null,
                                    ])>{{ $event->resolved_at ? 'Resolved' : 'Unresolved' }}</span>
                                </td>
                            @endif
                            <td class="px-[18px] py-3 text-sm text-gray-600">{{ app(\App\Support\SettingsDate::class)->formatDateTime($event->created_at) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canViewSensitiveDetails ? 8 : 7 }}" class="px-[18px] py-8 text-center text-sm text-gray-500">No error events match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    </div>

    @php($page = $groups ?? $events)
    <nav aria-label="Error event pagination" class="flex items-center justify-between border-t border-gray-100 px-[18px] py-3">
        <p class="text-xs text-gray-500">Showing {{ $page->firstItem() ?? 0 }}–{{ $page->lastItem() ?? 0 }} of {{ \App\Support\Plural::count($page->total(), $viewMode === 'groups' ? 'group' : 'event') }}</p>
        <div class="flex gap-1.5">
            <button
                type="button"
                aria-label="Previous page"
                wire:click="previousPage"
                @disabled($page->onFirstPage())
                class="flex size-7 items-center justify-center rounded-md border border-gray-300 bg-white text-xs text-gray-600 disabled:cursor-not-allowed disabled:text-gray-400"
            ><x-heroicon-o-chevron-left class="size-4" aria-hidden="true" /></button>
            <button
                type="button"
                aria-label="Next page"
                wire:click="nextPage"
                @disabled(! $page->hasMorePages())
                class="flex size-7 items-center justify-center rounded-md border border-gray-300 bg-white text-xs text-gray-600 disabled:cursor-not-allowed disabled:text-gray-400"
            ><x-heroicon-o-chevron-right class="size-4" aria-hidden="true" /></button>
        </div>
    </nav>

    @if ($selectedEvent)
        <div
            class="fixed inset-0 z-30 flex items-center justify-center bg-gray-950/40 p-4"
            role="presentation"
            x-data="{
                trapFocus(event) {
                    const focusable = [...this.$refs.dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=&quot;-1&quot;])')];
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];

                    if (event.shiftKey && (document.activeElement === first || !this.$refs.dialog.contains(document.activeElement))) {
                        event.preventDefault();
                        last?.focus();
                    } else if (!event.shiftKey && (document.activeElement === last || !this.$refs.dialog.contains(document.activeElement))) {
                        event.preventDefault();
                        first?.focus();
                    }
                }
            }"
            x-on:keydown.escape.window="$wire.closeDetails()"
            x-on:keydown.tab="trapFocus($event)"
            x-init="$nextTick(() => $refs.closeButton.focus())"
        >
            <section x-ref="dialog" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="error-details-heading" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white p-5 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="error-details-heading" class="break-all text-base font-bold text-gray-900">Error details · {{ $selectedEvent->reference }}</h2>
                        @if ($canViewSensitiveDetails)
                            <p class="mt-1 text-sm text-gray-500">{{ app(\App\Support\SettingsDate::class)->formatDateTime($selectedEvent->created_at, 'H:i:s') }}</p>
                        @endif
                    </div>
                    <button x-ref="closeButton" type="button" wire:click="closeDetails" aria-label="Close details" class="rounded px-2 py-1 text-gray-500 hover:bg-gray-100 hover:text-gray-800">×</button>
                </div>

                <div class="mt-3">
                    <button type="button" onclick="navigator.clipboard.writeText(@js($selectedEvent->reference))" class="rounded-md border border-gray-300 px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                        Copy reference
                    </button>
                </div>

                <dl class="mt-5 grid gap-3 text-sm">
                    <div>
                        <dt class="font-semibold text-gray-700">Exception class</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-gray-600">{{ $selectedEvent->exception_class ?? '—' }}</dd>
                    </div>
                    @if ($canViewSensitiveDetails)
                        <div>
                            <dt class="font-semibold text-gray-700">Status</dt>
                            <dd class="mt-0.5 text-gray-600">{{ $selectedEvent->status_code }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-700">State</dt>
                            <dd class="mt-0.5 text-gray-600">{{ $selectedEvent->resolved_at ? 'Resolved' : 'Unresolved' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-700">Path and method</dt>
                            <dd class="mt-0.5 break-all font-mono text-xs text-gray-600">{{ $selectedEvent->method }} {{ $selectedEvent->path }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-700">Module</dt>
                            <dd class="mt-0.5 text-gray-600">{{ $selectedEvent->module_slug ? str($selectedEvent->module_slug)->headline() : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-700">User and role</dt>
                            <dd class="mt-0.5 text-gray-600">{{ $selectedEvent->user?->name ?? 'Guest' }}{{ $selectedEvent->role_slug ? ' · '.str($selectedEvent->role_slug)->headline() : '' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-700">IP address</dt>
                            <dd class="mt-0.5 font-mono text-xs text-gray-600">{{ $selectedEvent->ip_address ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-700">Exception message</dt>
                            <dd class="mt-0.5 whitespace-pre-wrap break-words text-gray-600">{{ $selectedEvent->exception_message ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-700">File and line</dt>
                            <dd class="mt-0.5 break-all font-mono text-xs text-gray-600">{{ $selectedEvent->exception_file ?? '—' }}{{ $selectedEvent->exception_line ? ':'.$selectedEvent->exception_line : '' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-700">Trace (top 15 frames)</dt>
                            <dd class="mt-0.5 overflow-x-auto rounded bg-gray-50 p-2 font-mono text-xs text-gray-600"><pre class="whitespace-pre-wrap break-words">{{ json_encode($selectedEvent->trace ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></dd>
                        </div>
                    @endif
                </dl>

                @if ($canViewSensitiveDetails)
                    <div class="mt-5 border-t border-gray-100 pt-4">
                        <label for="error-resolution-note" class="block text-sm font-semibold text-gray-700">Resolution note (optional)</label>
                        <textarea id="error-resolution-note" wire:model="resolutionNote" maxlength="2000" rows="3" class="mt-1.5 w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700"></textarea>
                        @error('resolutionNote')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                        @if ($selectedEvent->resolution_note)
                            <p class="mt-2 whitespace-pre-wrap break-words text-sm text-gray-600">{{ $selectedEvent->resolution_note }}</p>
                        @endif
                        <div class="mt-3 flex justify-end gap-2">
                            @if ($selectedEvent->resolved_at)
                                <button type="button" wire:click="updateResolution(false)" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">Mark unresolved</button>
                            @else
                                <button type="button" wire:click="updateResolution(true)" class="rounded-md bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">Mark resolved</button>
                            @endif
                        </div>
                    </div>
                @endif
            </section>
        </div>
    @endif
    </section>
</div>
