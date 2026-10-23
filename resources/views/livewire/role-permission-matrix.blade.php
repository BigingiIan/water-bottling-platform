<section
    aria-labelledby="permission-matrix-heading"
    class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
    x-data
    x-on:role-deleted.window="$refs.toastMessage.textContent = $event.detail.message; $refs.toast.hidden = false; setTimeout(() => $refs.toast.hidden = true, 4000)"
    x-on:role-delete-modal-closed.window="$nextTick(() => document.getElementById('delete-role-' + $event.detail.roleId)?.focus())"
>
    <div x-ref="toast" hidden role="status" class="fixed right-4 top-4 z-40 rounded-md border border-green-200 bg-green-50 px-4 py-2.5 text-sm font-medium text-green-800 shadow-lg">
        <span x-ref="toastMessage"></span>
    </div>

    <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-[18px] py-4">
        <div>
            <h1 id="permission-matrix-heading" class="text-[15px] font-bold text-gray-900">Module Access by Role</h1>
            <p class="mt-0.5 text-xs text-gray-500">Toggle module-level access per role</p>
        </div>
        @if ($canManageRoles)
            <button
                type="button"
                wire:click="openCreateRoleModal"
                class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
            >
                + New Role
            </button>
        @endif
    </div>

    @error('roleAction')
        <p role="alert" class="border-b border-red-100 bg-red-50 px-[18px] py-2.5 text-sm text-red-700">{{ $message }}</p>
    @enderror

    <div class="overflow-x-auto">
        <table class="min-w-[920px] w-full border-collapse text-left">
            <caption class="sr-only">Module access permissions by role. Select a toggle to cycle between no access, view only, and full access.</caption>
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="sticky left-0 z-20 min-w-[190px] border-b border-gray-200 bg-gray-50 px-[18px] py-2.5 text-left text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">
                        Module
                    </th>
                    @foreach ($roles as $role)
                        @php
                            $roleBadge = $rolePresentation[$role->slug] ?? ['label' => $role->name, 'class' => 'bg-gray-100 text-gray-600'];
                        @endphp
                        <th wire:key="role-{{ $role->id }}" scope="col" class="min-w-[110px] border-b border-gray-200 px-[18px] py-2.5 text-center text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">
                            <span class="flex flex-col items-center gap-1">
                                <span @class(['inline-block rounded px-2.5 py-[3px] text-[11px] font-bold normal-case tracking-normal', $roleBadge['class']])>
                                    {{ $roleBadge['label'] }}
                                </span>
                                @if ($canManageRoles)
                                    <span class="flex items-center gap-2 normal-case tracking-normal">
                                        <button
                                            type="button"
                                            wire:click="openEditRoleModal({{ $role->id }})"
                                            class="font-medium text-blue-700 hover:text-blue-900"
                                        >
                                            Rename
                                        </button>
                                        <button
                                            type="button"
                                            id="delete-role-{{ $role->id }}"
                                            wire:click="openDeleteRoleModal({{ $role->id }})"
                                            class="font-medium text-red-700 hover:text-red-900"
                                        >
                                            {{ $role->isSystemRole() ? 'System role' : 'Delete' }}
                                        </button>
                                    </span>
                                @endif
                            </span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($modules as $module)
                    <tr wire:key="module-{{ $module->id }}" class="group hover:bg-gray-50">
                        <th scope="row" class="sticky left-0 z-10 min-w-[190px] border-b border-gray-100 bg-white px-[18px] py-3 text-left text-[13px] font-semibold text-gray-800 group-hover:bg-gray-50">
                            {{ $module->name }}
                            @if ($module->description)
                                <span class="mt-px block text-[10.5px] font-normal text-gray-400">{{ $module->description }}</span>
                            @endif
                        </th>
                        @foreach ($roles as $role)
                            @php
                                $permission = $module->roles->firstWhere('id', $role->id);
                                $accessLevel = $permission?->pivot->access_level;
                                $access = $accessPresentation[$accessLevel] ?? $accessPresentation['none'];
                            @endphp
                            <td wire:key="permission-{{ $role->id }}-{{ $module->id }}" class="border-b border-gray-100 px-[18px] py-3 text-center">
                                <button
                                    type="button"
                                    aria-label="{{ $role->name }} — {{ $module->name }}: {{ $access['label'] }}. Click to change access."
                                    title="{{ $access['label'] }} (click to cycle)"
                                    wire:click="cyclePermission({{ $role->id }}, {{ $module->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="cyclePermission({{ $role->id }}, {{ $module->id }})"
                                    @disabled($permission === null)
                                    @class([
                                        'relative inline-block h-5 w-[38px] rounded-full transition-colors disabled:cursor-not-allowed',
                                        $access['class'],
                                    ])
                                >
                                    <span
                                        aria-hidden="true"
                                        @class([
                                            'absolute top-0.5 size-4 rounded-full bg-white transition-[left] duration-150',
                                            'left-0.5' => $accessLevel === 'none',
                                            'left-5' => $accessLevel !== 'none',
                                        ])
                                    ></span>
                                </button>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div aria-label="Permission levels" class="flex flex-wrap items-center gap-x-[18px] gap-y-2 border-t border-gray-100 px-[18px] py-3 text-[11.5px] text-gray-500">
        <span class="flex items-center gap-1.5">
            <span aria-hidden="true" class="relative inline-block h-[15px] w-7 rounded-full bg-blue-600">
                <span class="absolute left-[15px] top-0.5 size-[11px] rounded-full bg-white"></span>
            </span>
            Full access
        </span>
        <span class="flex items-center gap-1.5">
            <span aria-hidden="true" class="relative inline-block h-[15px] w-7 rounded-full bg-amber-500">
                <span class="absolute left-[15px] top-0.5 size-[11px] rounded-full bg-white"></span>
            </span>
            View only
        </span>
        <span class="flex items-center gap-1.5">
            <span aria-hidden="true" class="relative inline-block h-[15px] w-7 rounded-full bg-gray-200">
                <span class="absolute left-0.5 top-0.5 size-[11px] rounded-full bg-white"></span>
            </span>
            No access
        </span>
    </div>

    @if ($showRoleModal)
        <div class="fixed inset-0 z-30 flex items-center justify-center bg-gray-950/40 p-4" role="presentation">
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="role-modal-heading"
                class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="role-modal-heading" class="text-base font-bold text-gray-900">
                            {{ $editingRoleId === null ? 'New Role' : 'Rename Role' }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-500">Set a role name and optional description.</p>
                    </div>
                    <button
                        type="button"
                        wire:click="closeRoleModal"
                        aria-label="Close"
                        class="rounded px-2 py-1 text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                    >×</button>
                </div>

                <form wire:submit="saveRole" class="mt-5 flex flex-col gap-4">
                    <div>
                        <label for="role-name" class="mb-1 block text-sm font-medium text-gray-700">Name</label>
                        <input
                            id="role-name"
                            type="text"
                            wire:model="name"
                            required
                            maxlength="255"
                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
                        >
                        @error('name')
                            <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="role-description" class="mb-1 block text-sm font-medium text-gray-700">Description <span class="font-normal text-gray-500">(optional)</span></label>
                        <textarea
                            id="role-description"
                            wire:model="description"
                            maxlength="1000"
                            rows="3"
                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
                        ></textarea>
                        @error('description')
                            <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            wire:click="closeRoleModal"
                            class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                        >
                            {{ $editingRoleId === null ? 'Create Role' : 'Save Changes' }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    @endif

    @if ($showDeleteRoleModal && $deletingRole)
        <div
            class="fixed inset-0 z-30 flex items-center justify-center bg-gray-950/40 p-4"
            role="presentation"
            x-data="{
                trapFocus(event) {
                    const focusable = [...this.$refs.dialog.querySelectorAll('button:not([disabled]), a[href]')];
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
            x-on:keydown.escape.window="$wire.closeDeleteRoleModal()"
            x-on:keydown.tab="trapFocus($event)"
            x-init="$nextTick(() => ($refs.cancelButton ?? $refs.closeButton).focus())"
        >
            <section
                x-ref="dialog"
                tabindex="-1"
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-role-heading"
                class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        @if ($deletingRole->isSystemRole())
                            <h2 id="delete-role-heading" class="text-base font-bold text-gray-900">Protected system role</h2>
                        @elseif ($deletingRole->users_count > 0)
                            <h2 id="delete-role-heading" class="text-base font-bold text-gray-900">Role cannot be deleted</h2>
                        @else
                            <h2 id="delete-role-heading" class="text-base font-bold text-gray-900">Delete role '{{ $deletingRole->name }}'?</h2>
                        @endif
                    </div>
                </div>

                @if ($deletingRole->isSystemRole())
                    <p class="mt-4 text-sm text-gray-700">'{{ $deletingRole->name }}' is a system role and cannot be deleted.</p>
                @elseif ($deletingRole->users_count > 0)
                    <p class="mt-4 text-sm text-gray-700">{{ $deletingRole->users_count }} users still have the role '{{ $deletingRole->name }}'. Reassign them before deleting it.</p>
                    <ul class="mt-3 list-inside list-disc text-sm text-gray-600">
                        @foreach ($deletingRoleUsers as $userName)
                            <li>{{ $userName }}</li>
                        @endforeach
                        @if ($deletingRole->users_count > $deletingRoleUsers->count())
                            <li>and {{ $deletingRole->users_count - $deletingRoleUsers->count() }} more</li>
                        @endif
                    </ul>
                    <a href="{{ $deletingRoleUsersUrl }}" class="mt-3 inline-block text-sm font-semibold text-blue-700 hover:text-blue-900 hover:underline">View users</a>
                @else
                    <div class="mt-4 flex flex-col gap-2 text-sm text-gray-700">
                        <p>Users with this role: {{ $deletingRole->users_count }}</p>
                        <p>Modules with access above 'none': {{ $deletingRole->accessible_modules_count }}</p>
                        <p>This cannot be undone.</p>
                    </div>
                @endif

                @if ($deleteRoleError)
                    <p role="alert" class="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $deleteRoleError }}</p>
                @endif

                <div class="mt-5 flex justify-end gap-2">
                    @if (! $deletingRole->isSystemRole() && $deletingRole->users_count === 0)
                        <button
                            x-ref="cancelButton"
                            type="button"
                            wire:click="closeDeleteRoleModal"
                            class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            wire:click="deleteRole"
                            wire:loading.attr="disabled"
                            class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
                        >
                            Delete role
                        </button>
                    @else
                        <button
                            x-ref="closeButton"
                            type="button"
                            wire:click="closeDeleteRoleModal"
                            class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Close
                        </button>
                    @endif
                </div>
            </section>
        </div>
    @endif
</section>
