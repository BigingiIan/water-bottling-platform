<div class="flex flex-col gap-6">
    <x-page-header heading-id="staff-directory-heading" title="Staff Directory" :subtitle="\App\Support\Plural::count($users->total(), 'user')">
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

    <section aria-labelledby="staff-directory-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">

    <div class="flex flex-wrap gap-2 border-b border-gray-100 bg-gray-50 px-[18px] py-3">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search users, roles…"
            aria-label="Search staff"
            class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700 placeholder:text-gray-400 sm:w-[220px]"
        >
        <select wire:model.live="roleFilter" aria-label="Filter by role" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600">
            <option value="">Role: All</option>
            @foreach ($roles as $role)
                <option value="{{ $role->id }}">Role: {{ $role->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="statusFilter" aria-label="Filter by status" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600">
            <option value="">Status: All</option>
            <option value="active">Status: Active</option>
            <option value="pending">Status: Pending Invite</option>
            <option value="suspended">Status: Suspended</option>
        </select>
        <select wire:model.live="moduleFilter" aria-label="Filter by module" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600">
            <option value="">Module: All</option>
            @foreach ($modules as $module)
                <option value="{{ $module->slug }}">Module: {{ $module->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] border-collapse text-left">
            <caption class="sr-only">Staff members, their roles, status, module access, and last active time</caption>
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">User</th>
                    <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Role</th>
                    <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Status</th>
                    <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Modules</th>
                    <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] whitespace-nowrap text-gray-500">Last Active</th>
                    <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $member)
                    @php
                        $roleStyles = $rolePresentation[$member->role->slug] ?? ['class' => 'bg-gray-100 text-gray-600', 'avatarColor' => '#475569'];
                        $statusStyles = $statusPresentation[$member->status];
                        $accessibleModules = $member->role->modules
                            ->filter(fn ($module) => $module->pivot->access_level !== 'none')
                            ->pluck('name')
                            ->implode(', ');
                        $initials = collect(explode(' ', $member->name))
                            ->filter()
                            ->take(2)
                            ->map(fn ($part) => mb_substr($part, 0, 1))
                            ->implode('');
                    @endphp
                    <tr wire:key="staff-{{ $member->id }}" class="transition-colors hover:bg-gray-50">
                        <td class="border-b border-gray-100 px-[18px] py-3">
                            <div class="flex items-center gap-2.5">
                                <span
                                    aria-hidden="true"
                                    class="flex size-8 shrink-0 items-center justify-center rounded-full text-[11.5px] font-bold text-white"
                                    style="background-color: {{ $roleStyles['avatarColor'] }}"
                                >
                                    {{ $initials }}
                                </span>
                                <span>
                                    <span class="block text-[13px] font-semibold text-gray-900">{{ $member->name }}</span>
                                    <span class="block text-[11.5px] text-gray-500">{{ $member->email }}</span>
                                </span>
                            </div>
                        </td>
                        <td class="border-b border-gray-100 px-[18px] py-3">
                            <span @class(['inline-block whitespace-nowrap rounded px-2.5 py-[3px] text-[11px] font-bold', $roleStyles['class']])>
                                {{ $member->role->name }}
                            </span>
                        </td>
                        <td class="border-b border-gray-100 px-[18px] py-3">
                            <span @class(['inline-flex items-center gap-[5px] rounded-full px-[9px] py-[3px] text-[11px] font-bold', $statusStyles['class']])>
                                <span aria-hidden="true" @class(['size-1.5 rounded-full', $statusStyles['dotClass']])></span>
                                {{ $statusStyles['label'] }}
                            </span>
                        </td>
                        <td class="border-b border-gray-100 px-[18px] py-3 text-xs leading-5 text-gray-600">{{ $accessibleModules ?: '—' }}</td>
                        <td class="whitespace-nowrap border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700">{{ $member->last_active_at?->diffForHumans() ?? '—' }}</td>
                        <td class="border-b border-gray-100 px-[18px] py-3">
                            @if ($canInvite)
                                <div class="relative flex justify-end">
                                    <button
                                        type="button"
                                        wire:click="toggleMenu({{ $member->id }})"
                                        aria-label="Actions for {{ $member->name }}"
                                        aria-expanded="{{ $openMenuUserId === $member->id ? 'true' : 'false' }}"
                                        class="flex size-7 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100"
                                    ><x-heroicon-o-ellipsis-horizontal class="size-5" aria-hidden="true" /></button>
                                    @if ($openMenuUserId === $member->id)
                                        <div role="menu" aria-label="Actions for {{ $member->name }}" class="absolute right-0 top-8 z-20 w-44 overflow-hidden rounded-md border border-gray-200 bg-white shadow-lg">
                                            <button type="button" role="menuitem" wire:click="$dispatch('open-role-modal', { userId: {{ $member->id }} })" class="block w-full px-3.5 py-2 text-left text-[13px] text-gray-700 hover:bg-gray-50">Change role</button>
                                            <button type="button" role="menuitem" wire:click="$dispatch('open-suspend-modal', { userId: {{ $member->id }} })" class="block w-full px-3.5 py-2 text-left text-[13px] text-gray-700 hover:bg-gray-50">{{ $member->status === 'suspended' ? 'Reactivate' : 'Suspend' }}</button>
                                            <button type="button" role="menuitem" wire:click="$dispatch('open-reset-modal', { userId: {{ $member->id }} })" class="block w-full px-3.5 py-2 text-left text-[13px] text-gray-700 hover:bg-gray-50">Reset password</button>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-[18px] py-8 text-center text-sm text-gray-500">No staff match these filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <nav aria-label="Staff directory pagination" class="flex items-center justify-between border-t border-gray-100 px-[18px] py-3">
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
                @php($previousPageNumber = $page)
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
</div>
