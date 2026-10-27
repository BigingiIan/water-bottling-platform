@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $thClasses = 'border-b border-gray-200 bg-gray-50 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500';
    $filterClasses = 'rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700';
    $tabs = ['inbox' => 'Inbox', 'sent' => 'Sent'] + ($isAdministrator ? ['all' => 'All requests'] : []);
@endphp

<div class="flex flex-col gap-6">
    <x-page-header help="requests" heading-id="requests-heading" title="Requests" subtitle="Ask another team to act, and follow up on what was asked of yours.">
        @if ($canSend)
            <x-slot:actions>
                <button type="button" wire:click="$dispatch('open-request-modal')" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">
                    <x-heroicon-o-plus class="size-4" aria-hidden="true" />
                    New request
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <nav aria-label="Request lists" class="flex flex-wrap gap-1 border-b border-gray-200">
        @foreach ($tabs as $tab => $label)
            <button type="button" wire:click="$set('activeTab', '{{ $tab }}')" @class(['inline-flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-[13px] font-semibold', 'border-blue-600 text-blue-700' => $activeTab === $tab, 'border-transparent text-gray-500 hover:text-gray-800' => $activeTab !== $tab]) @if ($activeTab === $tab) aria-current="page" @endif>
                {{ $label }}
                @if ($tab === 'inbox' && $inboxActiveCount > 0)
                    <span class="rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold text-blue-800">{{ $inboxActiveCount }}<span class="sr-only"> open</span></span>
                @endif
            </button>
        @endforeach
    </nav>

    <section aria-labelledby="requests-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-gray-50 px-[18px] py-3">
            <label class="relative w-full sm:w-[220px]">
                <span class="sr-only">Search requests</span>
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Reference or subject" class="w-full rounded-md border border-gray-300 bg-white py-1.5 pl-8 pr-2.5 text-xs text-gray-700 placeholder:text-gray-400">
            </label>
            <select wire:model.live="statusFilter" aria-label="Filter by status" class="{{ $filterClasses }}">
                <option value="active">Still open</option>
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="typeFilter" aria-label="Filter by type" class="{{ $filterClasses }}">
                <option value="">All types</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="priorityFilter" aria-label="Filter by priority" class="{{ $filterClasses }}">
                <option value="">All priorities</option>
                @foreach ($priorities as $priority)
                    <option value="{{ $priority->value }}">{{ $priority->label() }}</option>
                @endforeach
            </select>
            <label class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-700">
                <input type="checkbox" wire:model.live="overdueOnly" class="size-4 rounded border-gray-300 text-blue-600">
                Overdue only
            </label>
            @if ($hasActiveFilters)
                <button type="button" wire:click="clearFilters" class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 hover:text-blue-900">
                    <x-heroicon-o-x-mark class="size-3.5" aria-hidden="true" />
                    Clear filters
                </button>
            @endif
            <span class="ml-auto text-xs text-gray-500">{{ \App\Support\Plural::count($requests->total(), 'request') }}</span>
        </div>

        @if ($requests->isEmpty())
            <div class="px-[18px] py-10 text-center">
                <x-heroicon-o-inbox class="mx-auto size-8 text-gray-300" aria-hidden="true" />
                <p class="mt-2 text-sm font-semibold text-gray-700">
                    @if ($hasActiveFilters)
                        No requests match these filters.
                    @elseif ($activeTab === 'sent')
                        You haven't sent any requests yet.
                    @else
                        Nothing is waiting for your team.
                    @endif
                </p>
                <p class="mt-1 text-xs text-gray-500">Requests let you ask another department to act without needing access to their area.</p>
            </div>
        @else
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[820px] border-separate border-spacing-0 text-left">
                    <caption class="sr-only">Requests with their reference, subject, department, priority, status and due date</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $thClasses }}">Request</th>
                            <th scope="col" class="{{ $thClasses }}">{{ $activeTab === 'sent' ? 'To' : 'From' }}</th>
                            <th scope="col" class="{{ $thClasses }}">Priority</th>
                            <th scope="col" class="{{ $thClasses }}">Status</th>
                            <th scope="col" class="{{ $thClasses }} whitespace-nowrap">Due</th>
                            <th scope="col" class="{{ $thClasses }} whitespace-nowrap">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $request)
                            @php
                                $isOverdue = $request->isOverdue();
                            @endphp
                            <tr wire:key="request-row-{{ $request->id }}" class="group">
                                <td class="border-b border-gray-100 px-[18px] py-3 group-hover:bg-gray-50">
                                    <a href="{{ route('requests.show', $request) }}" class="flex min-w-0 items-start gap-2.5">
                                        <x-dynamic-component :component="'heroicon-o-'.$request->type->icon()" class="mt-0.5 size-5 shrink-0 text-gray-400" aria-hidden="true" />
                                        <span class="min-w-0">
                                            <span class="block truncate text-[13px] font-semibold text-gray-900 group-hover:text-blue-700">{{ $request->subject }}</span>
                                            <span class="block text-[11.5px] text-gray-500">
                                                <span class="font-mono">{{ $request->reference }}</span> · {{ $request->type->label() }}
                                                @if ($request->comments_count > 0)
                                                    · {{ \App\Support\Plural::count($request->comments_count, 'comment') }}
                                                @endif
                                            </span>
                                        </span>
                                    </a>
                                </td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700 group-hover:bg-gray-50">
                                    @if ($activeTab === 'sent')
                                        <span class="whitespace-nowrap">{{ $request->toRole?->name }}</span>
                                    @else
                                        <span class="block whitespace-nowrap">{{ $request->sender?->name }}</span>
                                        <span class="block whitespace-nowrap text-[11.5px] text-gray-500">to {{ $request->toRole?->name }}</span>
                                    @endif
                                </td>
                                <td class="border-b border-gray-100 px-[18px] py-3 group-hover:bg-gray-50"><x-request-priority-badge :priority="$request->priority" /></td>
                                <td class="border-b border-gray-100 px-[18px] py-3 group-hover:bg-gray-50"><x-request-status-badge :status="$request->status" /></td>
                                <td @class(['whitespace-nowrap border-b border-gray-100 px-[18px] py-3 text-[13px] group-hover:bg-gray-50', 'font-semibold text-[#b91c1c]' => $isOverdue, 'text-gray-700' => ! $isOverdue])>
                                    @if ($request->due_date)
                                        @if ($isOverdue)<x-heroicon-o-exclamation-triangle class="mr-0.5 inline size-4 align-[-3px]" aria-hidden="true" /><span class="sr-only">Overdue, was due </span>@endif{{ $settingsDate->formatDate($request->due_date) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="whitespace-nowrap border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-500 group-hover:bg-gray-50">{{ $request->updated_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($requests->hasPages())
            <div class="border-t border-gray-100 px-[18px] py-3">
                {{ $requests->links() }}
            </div>
        @endif
    </section>
</div>
