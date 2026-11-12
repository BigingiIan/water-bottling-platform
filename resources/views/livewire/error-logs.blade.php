<div class="flex flex-col gap-6">
    <x-page-header help="error-logs" heading-id="error-logs-heading" title="Error Logs" :subtitle="'Branded 403 and 500 errors from the last '.\App\Support\Plural::count((int) app(\App\Services\SettingsService::class)->get('retention.error_log_days', 30), 'day').'.'">
        <x-slot:actions>
            <div class="inline-flex rounded-md border border-border-strong bg-surface p-0.5 text-xs">
                <button type="button" wire:click="showGroups" @class(['rounded px-2.5 py-1.5', 'bg-accent text-on-accent' => $viewMode === 'groups', 'text-fg-soft hover:bg-surface-strong' => $viewMode !== 'groups']) aria-pressed="{{ $viewMode === 'groups' ? 'true' : 'false' }}">Grouped</button>
                <button type="button" wire:click="showEvents" @class(['rounded px-2.5 py-1.5', 'bg-accent text-on-accent' => $viewMode === 'events', 'text-fg-soft hover:bg-surface-strong' => $viewMode !== 'events']) aria-pressed="{{ $viewMode === 'events' ? 'true' : 'false' }}">Raw events</button>
            </div>
            <label class="sr-only" for="error-reference-search">Search reference code</label>
            <input
                id="error-reference-search"
                type="search"
                wire:model.live.debounce.300ms="referenceSearch"
                maxlength="9"
                placeholder="Search reference code…"
                class="w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-fg-soft placeholder:text-muted sm:w-64"
            >
        </x-slot:actions>
    </x-page-header>

    <section aria-labelledby="error-logs-heading" class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">

    <div class="flex flex-wrap gap-2 border-b border-border bg-surface-muted px-[18px] py-3">
        <select wire:model.live="statusFilter" aria-label="Filter by status" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
            <option value="">All statuses</option>
            <option value="403">403 — Forbidden</option>
            <option value="500">500 — Server error</option>
        </select>
        <select wire:model.live="userFilter" aria-label="Filter by user" class="max-w-56 rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
            <option value="">All users</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
            @endforeach
        </select>
        <select wire:model.live="moduleFilter" aria-label="Filter by module" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
            <option value="">All modules</option>
            @foreach ($modules as $module)
                <option value="{{ $module->slug }}">{{ $module->name }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-1.5 text-xs text-muted">
            From
            <input type="date" wire:model.live="startDate" aria-label="Start date" class="rounded-md border border-border-strong bg-surface px-2 py-1.5 text-xs text-fg-soft">
        </label>
        <label class="flex items-center gap-1.5 text-xs text-muted">
            To
            <input type="date" wire:model.live="endDate" aria-label="End date" class="rounded-md border border-border-strong bg-surface px-2 py-1.5 text-xs text-fg-soft">
        </label>
    </div>

    <div class="overflow-x-auto">
        @if ($viewMode === 'groups')
            <table class="w-full min-w-[760px] border-collapse text-left">
                <caption class="sr-only">Error groups ordered by most recent occurrence.</caption>
                <thead class="bg-surface-muted">
                    <tr>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Exception class</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Count</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">First seen</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($groups as $group)
                        <tr wire:key="error-group-{{ $group->fingerprint }}" class="border-b border-border hover:bg-surface-muted">
                            <td class="px-[18px] py-3 font-mono text-xs text-fg-soft">
                                <button type="button" wire:click="showGroupDetails('{{ $group->fingerprint }}')" class="break-all text-left font-semibold text-accent-ink hover:text-accent-ink hover:underline">
                                    {{ $group->exception_class ?? 'Unknown exception' }}
                                </button>
                            </td>
                            <td class="px-[18px] py-3 text-sm text-fg-soft">{{ $group->event_count }}</td>
                            <td class="px-[18px] py-3 text-sm text-fg-soft">{{ app(\App\Support\SettingsDate::class)->formatDateTime($group->first_seen) }}</td>
                            <td class="px-[18px] py-3 text-sm text-fg-soft">{{ app(\App\Support\SettingsDate::class)->formatDateTime($group->last_seen) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-[18px] py-8 text-center text-sm text-muted">No fingerprinted error groups match these filters. Older events remain available in the raw event list.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="w-full min-w-[960px] border-collapse text-left">
                <caption class="sr-only">Recorded branded application errors, newest first. Select a reference to view event details.</caption>
                <thead class="bg-surface-muted">
                    <tr>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Reference</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Status</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Path</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Method</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">User</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Module</th>
                        @if ($canViewSensitiveDetails)
                            <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Resolution</th>
                        @endif
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr wire:key="error-event-{{ $event->id }}" class="border-b border-border hover:bg-surface-muted">
                            <td class="px-[18px] py-3 font-mono text-xs">
                                <button
                                    type="button"
                                    wire:click="showDetails({{ $event->id }})"
                                    class="font-semibold text-accent-ink hover:text-accent-ink hover:underline"
                                >
                                    {{ $event->reference }}
                                </button>
                            </td>
                            <td class="px-[18px] py-3 text-sm">
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                    'bg-warning-soft text-warning-ink' => $event->status_code === 403,
                                    'bg-danger-soft text-danger-ink' => $event->status_code === 500,
                                ])>{{ $event->status_code }}</span>
                            </td>
                            <td class="max-w-64 truncate px-[18px] py-3 font-mono text-xs text-fg-soft">{{ $event->path }}</td>
                            <td class="px-[18px] py-3 text-sm text-fg-soft">{{ $event->method }}</td>
                            <td class="px-[18px] py-3 text-sm text-fg-soft">{{ $event->user?->name ?? 'Guest' }}</td>
                            <td class="px-[18px] py-3 text-sm text-fg-soft">{{ $event->module_slug ? str($event->module_slug)->headline() : '—' }}</td>
                            @if ($canViewSensitiveDetails)
                                <td class="px-[18px] py-3 text-sm">
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                        'bg-success-soft text-success-ink' => $event->resolved_at !== null,
                                        'bg-surface-strong text-fg-soft' => $event->resolved_at === null,
                                    ])>{{ $event->resolved_at ? 'Resolved' : 'Unresolved' }}</span>
                                </td>
                            @endif
                            <td class="px-[18px] py-3 text-sm text-fg-soft">{{ app(\App\Support\SettingsDate::class)->formatDateTime($event->created_at) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canViewSensitiveDetails ? 8 : 7 }}"><x-empty-state key="error_logs" icon="shield-check" :filtered="$statusFilter !== null || $userFilter !== null || $moduleFilter !== null || $referenceSearch !== ''" /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    </div>

    @php($page = $groups ?? $events)
    <nav aria-label="Error event pagination" class="flex items-center justify-between border-t border-border px-[18px] py-3">
        <p class="text-xs text-muted">Showing {{ $page->firstItem() ?? 0 }}–{{ $page->lastItem() ?? 0 }} of {{ \App\Support\Plural::count($page->total(), $viewMode === 'groups' ? 'group' : 'event') }}</p>
        <div class="flex gap-1.5">
            <button
                type="button"
                aria-label="Previous page"
                wire:click="previousPage"
                @disabled($page->onFirstPage())
                class="flex size-7 items-center justify-center rounded-md border border-border-strong bg-surface text-xs text-fg-soft disabled:cursor-not-allowed disabled:text-muted"
            ><x-heroicon-o-chevron-left class="size-4" aria-hidden="true" /></button>
            <button
                type="button"
                aria-label="Next page"
                wire:click="nextPage"
                @disabled(! $page->hasMorePages())
                class="flex size-7 items-center justify-center rounded-md border border-border-strong bg-surface text-xs text-fg-soft disabled:cursor-not-allowed disabled:text-muted"
            ><x-heroicon-o-chevron-right class="size-4" aria-hidden="true" /></button>
        </div>
    </nav>

    @if ($selectedEvent)
        <div
            class="fixed inset-0 z-30 flex items-center justify-center bg-overlay p-4"
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
            <section x-ref="dialog" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="error-details-heading" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-surface p-5 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="error-details-heading" class="break-all text-base font-bold text-fg">Error details · {{ $selectedEvent->reference }}</h2>
                        @if ($canViewSensitiveDetails)
                            <p class="mt-1 text-sm text-muted">{{ app(\App\Support\SettingsDate::class)->formatDateTime($selectedEvent->created_at, 'H:i:s') }}</p>
                        @endif
                    </div>
                    <button x-ref="closeButton" type="button" wire:click="closeDetails" aria-label="Close details" class="rounded px-2 py-1 text-muted hover:bg-surface-strong hover:text-fg">×</button>
                </div>

                <div class="mt-3">
                    <button type="button" onclick="navigator.clipboard.writeText(@js($selectedEvent->reference))" class="rounded-md border border-border-strong px-2.5 py-1.5 text-xs font-semibold text-fg-soft hover:bg-surface-muted">
                        Copy reference
                    </button>
                </div>

                <dl class="mt-5 grid gap-3 text-sm">
                    <div>
                        <dt class="font-semibold text-fg-soft">Exception class</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-fg-soft">{{ $selectedEvent->exception_class ?? '—' }}</dd>
                    </div>
                    @if ($canViewSensitiveDetails)
                        <div>
                            <dt class="font-semibold text-fg-soft">Status</dt>
                            <dd class="mt-0.5 text-fg-soft">{{ $selectedEvent->status_code }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-fg-soft">State</dt>
                            <dd class="mt-0.5 text-fg-soft">{{ $selectedEvent->resolved_at ? 'Resolved' : 'Unresolved' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-fg-soft">Path and method</dt>
                            <dd class="mt-0.5 break-all font-mono text-xs text-fg-soft">{{ $selectedEvent->method }} {{ $selectedEvent->path }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-fg-soft">Module</dt>
                            <dd class="mt-0.5 text-fg-soft">{{ $selectedEvent->module_slug ? str($selectedEvent->module_slug)->headline() : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-fg-soft">User and role</dt>
                            <dd class="mt-0.5 text-fg-soft">{{ $selectedEvent->user?->name ?? 'Guest' }}{{ $selectedEvent->role_slug ? ' · '.str($selectedEvent->role_slug)->headline() : '' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-fg-soft">IP address</dt>
                            <dd class="mt-0.5 font-mono text-xs text-fg-soft">{{ $selectedEvent->ip_address ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-fg-soft">Exception message</dt>
                            <dd class="mt-0.5 whitespace-pre-wrap break-words text-fg-soft">{{ $selectedEvent->exception_message ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-fg-soft">File and line</dt>
                            <dd class="mt-0.5 break-all font-mono text-xs text-fg-soft">{{ $selectedEvent->exception_file ?? '—' }}{{ $selectedEvent->exception_line ? ':'.$selectedEvent->exception_line : '' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-fg-soft">Trace (top 15 frames)</dt>
                            <dd class="mt-0.5 overflow-x-auto rounded bg-surface-muted p-2 font-mono text-xs text-fg-soft"><pre class="whitespace-pre-wrap break-words">{{ json_encode($selectedEvent->trace ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></dd>
                        </div>
                    @endif
                </dl>

                @if ($canViewSensitiveDetails)
                    <div class="mt-5 border-t border-border pt-4">
                        <label for="error-resolution-note" class="block text-sm font-semibold text-fg-soft">Resolution note (optional)</label>
                        <textarea id="error-resolution-note" wire:model="resolutionNote" maxlength="2000" rows="3" class="mt-1.5 w-full rounded-md border border-border-strong px-3 py-2 text-sm text-fg-soft"></textarea>
                        @error('resolutionNote')
                            <p class="mt-1 text-xs text-danger-ink">{{ $message }}</p>
                        @enderror
                        @if ($selectedEvent->resolution_note)
                            <p class="mt-2 whitespace-pre-wrap break-words text-sm text-fg-soft">{{ $selectedEvent->resolution_note }}</p>
                        @endif
                        <div class="mt-3 flex justify-end gap-2">
                            @if ($selectedEvent->resolved_at)
                                <button type="button" wire:click="updateResolution(false)" class="rounded-md border border-border-strong px-3 py-2 text-xs font-semibold text-fg-soft hover:bg-surface-muted">Mark unresolved</button>
                            @else
                                <button type="button" wire:click="updateResolution(true)" class="rounded-md bg-accent px-3 py-2 text-xs font-semibold text-on-accent hover:bg-accent-hover">Mark resolved</button>
                            @endif
                        </div>
                    </div>
                @endif
            </section>
        </div>
    @endif
    </section>
</div>
