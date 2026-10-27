@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $hasActiveFilters = $search !== '' || $roleFilter !== '' || $statusFilter !== '' || $moduleFilter !== '';
    $initialsFor = fn (string $name): string => collect(explode(' ', $name))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_substr($part, 0, 1))
        ->implode('');
    $selectClasses = 'rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700';
@endphp
<div class="flex flex-col gap-6" x-on:keydown.escape.window="if ($wire.selectedUserId) { $wire.closeUser() } else if ($wire.openMenuUserId) { $wire.closeMenus() }">
    <x-page-header help="users-roles" heading-id="staff-directory-heading" title="Staff Directory" subtitle="Everyone with an AquaOps account, their role and what they can access.">
        <x-slot:actions>
            <button type="button" wire:click="exportCsv" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">
                <x-heroicon-o-arrow-down-tray class="size-4" aria-hidden="true" />
                Export CSV
            </button>
            @if ($canInvite)
                <button type="button" wire:click="$dispatch('open-invite-modal')" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">
                    <x-heroicon-o-plus class="size-4" aria-hidden="true" />
                    Invite User
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <section aria-labelledby="staff-directory-heading" class="rounded-lg border border-gray-200 bg-white">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-[18px] py-3.5">
            <div>
                <h2 class="text-sm font-bold text-gray-900">Team members</h2>
                <p class="mt-0.5 text-xs text-gray-500">Select a person to see their access and recent activity.</p>
            </div>
            <span class="text-xs text-gray-500">{{ \App\Support\Plural::count($users->total(), 'user') }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-gray-50 px-[18px] py-3">
            <label class="relative w-full sm:w-[240px]">
                <span class="sr-only">Search staff</span>
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search name, email or role"
                    aria-label="Search staff"
                    class="w-full rounded-md border border-gray-300 bg-white py-1.5 pl-8 pr-2.5 text-xs text-gray-700 placeholder:text-gray-400"
                >
            </label>
            <select wire:model.live="roleFilter" aria-label="Filter by role" class="{{ $selectClasses }}">
                <option value="">All roles</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="statusFilter" aria-label="Filter by status" class="{{ $selectClasses }}">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="pending">Pending Invite</option>
                <option value="suspended">Suspended</option>
            </select>
            <select wire:model.live="moduleFilter" aria-label="Filter by module" class="{{ $selectClasses }}">
                <option value="">All modules</option>
                @foreach ($modules as $module)
                    <option value="{{ $module->slug }}">{{ $module->name }}</option>
                @endforeach
            </select>
            @if ($hasActiveFilters)
                <button type="button" wire:click="clearFilters" class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 hover:text-blue-900">
                    <x-heroicon-o-x-mark class="size-3.5" aria-hidden="true" />
                    Clear filters
                </button>
            @endif
        </div>

        @if ($users->isNotEmpty())
            <div class="relative overflow-x-auto lg:overflow-visible">
                <table class="w-full min-w-[760px] border-separate border-spacing-0 text-left">
                    <caption class="sr-only">Staff members, their roles, status, module access, and last active time</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="border-b border-gray-200 bg-gray-50 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">User</th>
                            <th scope="col" class="border-b border-gray-200 bg-gray-50 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Role</th>
                            <th scope="col" class="border-b border-gray-200 bg-gray-50 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Status</th>
                            <th scope="col" class="border-b border-gray-200 bg-gray-50 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Modules</th>
                            <th scope="col" class="whitespace-nowrap border-b border-gray-200 bg-gray-50 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Last Active</th>
                            <th scope="col" class="border-b border-gray-200 bg-gray-50 px-[18px] py-2.5"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $member)
                            @php
                                $roleStyles = $rolePresentation[$member->role->slug] ?? ['class' => 'bg-gray-100 text-gray-600', 'avatarColor' => '#475569'];
                                $statusStyles = $statusPresentation[$member->status];
                                $accessibleModules = $member->role->modules
                                    ->filter(fn ($module) => $module->pivot->access_level !== 'none')
                                    ->pluck('name');
                                $opensUpward = $loop->count > 2 && $loop->remaining < 2;
                            @endphp
                            <tr wire:key="staff-{{ $member->id }}" @class(['group', 'bg-blue-50' => $selectedUserId === $member->id])>
                                <td class="border-b border-gray-100 px-[18px] py-3 group-hover:bg-gray-50">
                                    <button type="button" wire:click="openUser({{ $member->id }})" class="flex min-w-0 items-center gap-2.5 text-left">
                                        <span
                                            aria-hidden="true"
                                            class="flex size-8 shrink-0 items-center justify-center rounded-full text-[11.5px] font-bold text-white"
                                            style="background-color: {{ $roleStyles['avatarColor'] }}"
                                        >
                                            {{ $initialsFor($member->name) }}
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block truncate text-[13px] font-semibold text-gray-900 group-hover:text-blue-700">{{ $member->name }}</span>
                                            <span class="block truncate text-[11.5px] text-gray-500">{{ $member->email }}</span>
                                        </span>
                                    </button>
                                </td>
                                <td class="border-b border-gray-100 px-[18px] py-3 group-hover:bg-gray-50">
                                    <span @class(['inline-block whitespace-nowrap rounded px-2.5 py-[3px] text-[11px] font-bold', $roleStyles['class']])>
                                        {{ $member->role->name }}
                                    </span>
                                </td>
                                <td class="border-b border-gray-100 px-[18px] py-3 group-hover:bg-gray-50">
                                    <span @class(['inline-flex items-center gap-[5px] whitespace-nowrap rounded-full px-[9px] py-[3px] text-[11px] font-bold', $statusStyles['class']])>
                                        <span aria-hidden="true" @class(['size-1.5 rounded-full', $statusStyles['dotClass']])></span>
                                        {{ $statusStyles['label'] }}
                                    </span>
                                </td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-xs text-gray-600 group-hover:bg-gray-50" title="{{ $accessibleModules->implode(', ') }}">
                                    @if ($accessibleModules->isEmpty())
                                        —
                                    @else
                                        {{ $accessibleModules->take(3)->implode(', ') }}@if ($accessibleModules->count() > 3)<span class="whitespace-nowrap text-gray-400"> +{{ $accessibleModules->count() - 3 }} more</span>@endif
                                    @endif
                                </td>
                                <td class="whitespace-nowrap border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700 group-hover:bg-gray-50">{{ $member->last_active_at?->diffForHumans() ?? '—' }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 group-hover:bg-gray-50">
                                    <div class="relative flex justify-end">
                                        @if ($canInvite)
                                            <button
                                                type="button"
                                                wire:click="toggleMenu({{ $member->id }})"
                                                aria-label="Actions for {{ $member->name }}"
                                                aria-haspopup="menu"
                                                aria-expanded="{{ $openMenuUserId === $member->id ? 'true' : 'false' }}"
                                                class="flex size-7 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100"
                                            ><x-heroicon-o-ellipsis-horizontal class="size-5" aria-hidden="true" /></button>
                                            @if ($openMenuUserId === $member->id)
                                                <div
                                                    role="menu"
                                                    aria-label="Actions for {{ $member->name }}"
                                                    x-on:click.outside="$wire.closeMenus()"
                                                    @class([
                                                        'absolute right-0 z-20 w-48 overflow-hidden rounded-md border border-gray-200 bg-white py-1 shadow-lg',
                                                        'bottom-8' => $opensUpward,
                                                        'top-8' => ! $opensUpward,
                                                    ])
                                                >
                                                    <button type="button" role="menuitem" wire:click="openUser({{ $member->id }})" class="flex w-full items-center gap-2 px-3.5 py-2 text-left text-[13px] text-gray-700 hover:bg-gray-50">
                                                        <x-heroicon-o-identification class="size-4 text-gray-400" aria-hidden="true" />
                                                        View details
                                                    </button>
                                                    <button type="button" role="menuitem" wire:click="$dispatch('open-role-modal', { userId: {{ $member->id }} })" class="flex w-full items-center gap-2 px-3.5 py-2 text-left text-[13px] text-gray-700 hover:bg-gray-50">
                                                        <x-heroicon-o-shield-check class="size-4 text-gray-400" aria-hidden="true" />
                                                        Change role
                                                    </button>
                                                    <button type="button" role="menuitem" wire:click="$dispatch('open-reset-modal', { userId: {{ $member->id }} })" class="flex w-full items-center gap-2 px-3.5 py-2 text-left text-[13px] text-gray-700 hover:bg-gray-50">
                                                        <x-heroicon-o-key class="size-4 text-gray-400" aria-hidden="true" />
                                                        Reset password
                                                    </button>
                                                    <div class="my-1 border-t border-gray-100"></div>
                                                    <button type="button" role="menuitem" wire:click="$dispatch('open-suspend-modal', { userId: {{ $member->id }} })" @class([
                                                        'flex w-full items-center gap-2 px-3.5 py-2 text-left text-[13px] hover:bg-gray-50',
                                                        'text-green-700' => $member->status === 'suspended',
                                                        'text-red-700' => $member->status !== 'suspended',
                                                    ])>
                                                        <x-dynamic-component :component="$member->status === 'suspended' ? 'heroicon-o-arrow-path' : 'heroicon-o-no-symbol'" class="size-4" aria-hidden="true" />
                                                        {{ $member->status === 'suspended' ? 'Reactivate' : 'Suspend' }}
                                                    </button>
                                                </div>
                                            @endif
                                        @else
                                            <button
                                                type="button"
                                                wire:click="openUser({{ $member->id }})"
                                                aria-label="View details for {{ $member->name }}"
                                                class="flex size-7 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100"
                                            ><x-heroicon-o-chevron-right class="size-4" aria-hidden="true" /></button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="px-[18px] py-10 text-center">
                <x-heroicon-o-users class="mx-auto size-8 text-gray-300" aria-hidden="true" />
                <p class="mt-2 text-sm font-semibold text-gray-700">{{ $hasActiveFilters ? 'No staff match these filters.' : 'No staff have been added yet.' }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $hasActiveFilters ? 'Try a different search or clear the filters.' : 'Invite someone to give them an account.' }}</p>
                @if ($hasActiveFilters)
                    <button type="button" wire:click="clearFilters" class="mt-3 inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                        <x-heroicon-o-x-mark class="size-3.5" aria-hidden="true" />
                        Clear filters
                    </button>
                @elseif ($canInvite)
                    <button type="button" wire:click="$dispatch('open-invite-modal')" class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                        <x-heroicon-o-plus class="size-3.5" aria-hidden="true" />
                        Invite User
                    </button>
                @endif
            </div>
        @endif

        <nav aria-label="Staff directory pagination" class="flex items-center justify-between gap-3 border-t border-gray-100 px-[18px] py-3">
            <p class="text-xs text-gray-500">Showing {{ $users->firstItem() ?? 0 }}–{{ $users->lastItem() ?? 0 }} of {{ \App\Support\Plural::count($users->total(), 'user') }}</p>
            <div class="flex gap-1.5">
                <button
                    type="button"
                    aria-label="Previous page"
                    wire:click="previousPage"
                    @disabled($users->onFirstPage())
                    class="flex size-7 items-center justify-center rounded-md border border-gray-300 bg-white text-xs text-gray-600 disabled:cursor-not-allowed disabled:text-gray-400"
                ><x-heroicon-o-chevron-left class="size-4" aria-hidden="true" /></button>
                @php
                    $visiblePages = collect([1, $users->currentPage() - 1, $users->currentPage(), $users->currentPage() + 1, $users->lastPage()])
                        ->filter(fn ($page) => $page >= 1 && $page <= $users->lastPage())
                        ->unique()
                        ->sort()
                        ->values();
                    $previousPageNumber = null;
                @endphp
                @foreach ($visiblePages as $page)
                    @if ($previousPageNumber !== null && $page > $previousPageNumber + 1)
                        <span aria-hidden="true" class="flex size-7 items-center justify-center text-xs text-gray-500">…</span>
                    @endif
                    <button
                        type="button"
                        aria-label="Page {{ $page }}"
                        wire:click="gotoPage({{ $page }})"
                        @if ($page === $users->currentPage()) aria-current="page" @endif
                        @class([
                            'flex size-7 items-center justify-center rounded-md border text-xs',
                            'border-navy-900 bg-navy-900 text-white' => $page === $users->currentPage(),
                            'border-gray-300 bg-white text-gray-600' => $page !== $users->currentPage(),
                        ])
                    >
                        {{ $page }}
                    </button>
                    @php
                        $previousPageNumber = $page;
                    @endphp
                @endforeach
                <button
                    type="button"
                    aria-label="Next page"
                    wire:click="nextPage"
                    @disabled(! $users->hasMorePages())
                    class="flex size-7 items-center justify-center rounded-md border border-gray-300 bg-white text-xs text-gray-600 disabled:cursor-not-allowed disabled:text-gray-400"
                ><x-heroicon-o-chevron-right class="size-4" aria-hidden="true" /></button>
            </div>
        </nav>

        <livewire:invite-user-modal />

        <livewire:user-actions-modal />
    </section>

    @if ($selectedMember)
        @php
            $panelRole = $rolePresentation[$selectedMember->role?->slug] ?? ['class' => 'bg-gray-100 text-gray-600', 'avatarColor' => '#475569'];
            $panelStatus = $statusPresentation[$selectedMember->status];
            $panelModules = $selectedMember->role?->modules
                ->filter(fn ($module) => $module->pivot->access_level !== 'none')
                ->sortBy('name') ?? collect();
        @endphp
        <div class="fixed inset-0 z-30 bg-gray-900/30" wire:click="closeUser" aria-hidden="true"></div>
        <aside
            role="dialog"
            aria-modal="true"
            aria-labelledby="staff-panel-heading"
            x-data
            x-init="$nextTick(() => $refs.closePanel.focus())"
            class="fixed inset-y-0 right-0 z-40 flex w-full max-w-[420px] flex-col bg-white shadow-xl"
        >
            <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4">
                <div class="flex min-w-0 items-center gap-3">
                    <span aria-hidden="true" class="flex size-11 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white" style="background-color: {{ $panelRole['avatarColor'] }}">
                        {{ $initialsFor($selectedMember->name) }}
                    </span>
                    <div class="min-w-0">
                        <h2 id="staff-panel-heading" class="truncate text-base font-bold text-gray-900">{{ $selectedMember->name }}</h2>
                        <p class="truncate text-xs text-gray-500">{{ $selectedMember->email }}</p>
                    </div>
                </div>
                <button type="button" x-ref="closePanel" wire:click="closeUser" aria-label="Close details for {{ $selectedMember->name }}" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                    <x-heroicon-o-x-mark class="size-5" aria-hidden="true" />
                </button>
            </div>

            <div class="flex-1 overflow-y-auto">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3.5 border-b border-gray-100 px-5 py-4 text-[13px]">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-gray-500">Role</dt>
                        <dd class="mt-1"><span @class(['inline-block whitespace-nowrap rounded px-2.5 py-[3px] text-[11px] font-bold', $panelRole['class']])>{{ $selectedMember->role?->name ?? 'No role' }}</span></dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-gray-500">Status</dt>
                        <dd class="mt-1">
                            <span @class(['inline-flex items-center gap-[5px] whitespace-nowrap rounded-full px-[9px] py-[3px] text-[11px] font-bold', $panelStatus['class']])>
                                <span aria-hidden="true" @class(['size-1.5 rounded-full', $panelStatus['dotClass']])></span>
                                {{ $panelStatus['label'] }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-gray-500">Last active</dt>
                        <dd class="mt-1 text-gray-800">
                            @if ($selectedMember->last_active_at)
                                {{ $selectedMember->last_active_at->diffForHumans() }}
                                <span class="block text-[11.5px] text-gray-500">{{ $settingsDate->formatDateTime($selectedMember->last_active_at) }}</span>
                            @else
                                Never signed in
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-gray-500">Member since</dt>
                        <dd class="mt-1 text-gray-800">{{ $settingsDate->formatInstantDate($selectedMember->created_at) }}</dd>
                    </div>
                </dl>

                <section aria-labelledby="staff-panel-modules" class="border-b border-gray-100 px-5 py-4">
                    <h3 id="staff-panel-modules" class="text-[11px] font-semibold uppercase tracking-[0.4px] text-gray-500">Module access</h3>
                    @if ($panelModules->isEmpty())
                        <p class="mt-2 text-[13px] text-gray-500">This role has no module access.</p>
                    @else
                        <ul class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($panelModules as $module)
                                <li class="inline-flex items-center gap-1 whitespace-nowrap rounded bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-700">
                                    {{ $module->name }}
                                    <span class="font-normal text-gray-500">· {{ $module->pivot->access_level === 'full' ? 'Full' : 'View' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section aria-labelledby="staff-panel-activity" class="px-5 py-4">
                    <h3 id="staff-panel-activity" class="text-[11px] font-semibold uppercase tracking-[0.4px] text-gray-500">Recent activity</h3>
                    @if ($selectedMemberActivity->isEmpty())
                        <p class="mt-2 text-[13px] text-gray-500">No recorded activity yet.</p>
                    @else
                        <ol class="mt-2 space-y-3">
                            @foreach ($selectedMemberActivity as $entry)
                                <li wire:key="staff-activity-{{ $entry->id }}" class="flex gap-2.5 text-[13px]">
                                    <span aria-hidden="true" class="mt-1.5 size-1.5 shrink-0 rounded-full bg-gray-300"></span>
                                    <span class="min-w-0">
                                        <span class="block text-gray-800">
                                            {{ \Illuminate\Support\Str::headline(str_replace('.', ' ', $entry->action)) }}
                                            @if ($entry->user_id !== $selectedMember->id)
                                                <span class="text-gray-500">by {{ $entry->user?->name ?? 'System' }}</span>
                                            @elseif ($entry->targetUser && $entry->target_user_id !== $selectedMember->id)
                                                <span class="text-gray-500">for {{ $entry->targetUser->name }}</span>
                                            @endif
                                        </span>
                                        <span class="block text-[11px] text-gray-400">{{ $entry->timestamp?->diffForHumans() }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </div>

            @if ($canInvite)
                <div class="flex flex-wrap gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3.5">
                    <button type="button" wire:click="$dispatch('open-role-modal', { userId: {{ $selectedMember->id }} })" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">Change role</button>
                    <button type="button" wire:click="$dispatch('open-reset-modal', { userId: {{ $selectedMember->id }} })" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">Reset password</button>
                    <button type="button" wire:click="$dispatch('open-suspend-modal', { userId: {{ $selectedMember->id }} })" @class([
                        'rounded-md border px-3 py-1.5 text-xs font-semibold',
                        'border-green-200 bg-white text-green-700 hover:bg-green-50' => $selectedMember->status === 'suspended',
                        'border-red-200 bg-white text-red-700 hover:bg-red-50' => $selectedMember->status !== 'suspended',
                    ])>{{ $selectedMember->status === 'suspended' ? 'Reactivate' : 'Suspend' }}</button>
                </div>
            @endif
        </aside>
    @endif
</div>
