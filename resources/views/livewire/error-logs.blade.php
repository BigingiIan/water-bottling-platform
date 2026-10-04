<section aria-labelledby="error-logs-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 px-[18px] py-4">
        <div>
            <h1 id="error-logs-heading" class="text-[15px] font-bold text-gray-900">Error Logs</h1>
            <p class="mt-0.5 text-xs text-gray-500">Branded 403 and 500 errors from the last 30 days</p>
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
    </div>

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
        <table class="w-full min-w-[760px] border-collapse text-left">
            <caption class="sr-only">Recorded branded application errors, newest first. Select a reference to view event details.</caption>
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Reference</th>
                    <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Status</th>
                    <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">User</th>
                    <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Module</th>
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
                        <td class="px-[18px] py-3 text-sm text-gray-700">{{ $event->status_code }}</td>
                        <td class="px-[18px] py-3 text-sm text-gray-700">{{ $event->user?->name ?? 'Guest' }}</td>
                        <td class="px-[18px] py-3 text-sm text-gray-700">{{ $event->module_slug ? str($event->module_slug)->headline() : '—' }}</td>
                        <td class="px-[18px] py-3 text-sm text-gray-600">{{ $event->created_at->format('d M Y, H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-[18px] py-8 text-center text-sm text-gray-500">No error events match these filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <nav aria-label="Error event pagination" class="flex items-center justify-between border-t border-gray-100 px-[18px] py-3">
        <p class="text-xs text-gray-500">Showing {{ $events->firstItem() ?? 0 }}–{{ $events->lastItem() ?? 0 }} of {{ $events->total() }} events</p>
        <div class="flex gap-1.5">
            <button
                type="button"
                aria-label="Previous page"
                wire:click="previousPage"
                @disabled($events->onFirstPage())
                class="flex size-7 items-center justify-center rounded-md border border-gray-300 bg-white text-xs text-gray-600 disabled:cursor-not-allowed disabled:text-gray-400"
            >‹</button>
            <button
                type="button"
                aria-label="Next page"
                wire:click="nextPage"
                @disabled(! $events->hasMorePages())
                class="flex size-7 items-center justify-center rounded-md border border-gray-300 bg-white text-xs text-gray-600 disabled:cursor-not-allowed disabled:text-gray-400"
            >›</button>
        </div>
    </nav>

    @if ($selectedEvent)
        <div class="fixed inset-0 z-30 flex items-center justify-center bg-gray-950/40 p-4" role="presentation">
            <section role="dialog" aria-modal="true" aria-labelledby="error-details-heading" class="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="error-details-heading" class="text-base font-bold text-gray-900">Error {{ $selectedEvent->status_code }} · {{ $selectedEvent->reference }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ $selectedEvent->created_at->format('d M Y, H:i:s') }}</p>
                    </div>
                    <button type="button" wire:click="closeDetails" aria-label="Close details" class="rounded px-2 py-1 text-gray-500 hover:bg-gray-100 hover:text-gray-800">×</button>
                </div>

                <dl class="mt-5 grid gap-3 text-sm">
                    <div>
                        <dt class="font-semibold text-gray-700">User</dt>
                        <dd class="mt-0.5 text-gray-600">{{ $selectedEvent->user?->name ?? 'Guest' }}{{ $selectedEvent->user?->email ? ' ('.$selectedEvent->user->email.')' : '' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-700">Module</dt>
                        <dd class="mt-0.5 text-gray-600">{{ $selectedEvent->module_slug ? str($selectedEvent->module_slug)->headline() : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-700">Request</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-gray-600">{{ $selectedEvent->method }} {{ $selectedEvent->url }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-700">Exception</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-gray-600">{{ $selectedEvent->exception_class ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-700">Message</dt>
                        <dd class="mt-0.5 whitespace-pre-wrap break-words text-gray-600">{{ $selectedEvent->message }}</dd>
                    </div>
                </dl>
            </section>
        </div>
    @endif
</section>
