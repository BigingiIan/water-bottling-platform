@php
    $levelStyles = [
        'none' => 'bg-border text-fg',
        'view' => 'bg-warning-soft text-warning-ink ring-1 ring-inset ring-warning/35',
        'full' => 'bg-accent text-on-accent',
    ];
    $pendingChangeCount = count($pendingPermissions);
@endphp
<div
    class="flex flex-col gap-6"
    x-data="{
        showToast(message) {
            this.$refs.toastMessage.textContent = message;
            this.$refs.toast.hidden = false;
            setTimeout(() => this.$refs.toast.hidden = true, 4000);
        },
    }"
    x-on:role-deleted.window="showToast($event.detail.message)"
    x-on:permissions-saved.window="showToast($event.detail.message)"
    x-on:role-delete-modal-closed.window="$nextTick(() => document.getElementById('delete-role-' + $event.detail.roleId)?.focus())"
    x-on:beforeunload.window="if (Object.keys($wire.pendingPermissions ?? {}).length > 0) { $event.preventDefault(); $event.returnValue = ''; }"
>
    <div x-ref="toast" hidden role="status" class="fixed right-4 top-4 z-40 rounded-md border border-success/35 bg-success-soft px-4 py-2.5 text-sm font-medium text-success-ink shadow-lg">
        <span x-ref="toastMessage"></span>
    </div>

    <x-page-header help="users-roles" heading-id="permission-matrix-heading" title="Roles & Permissions" subtitle="Decide which parts of AquaOps each role can use.">
        @if ($canManageRoles)
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="openCreateRoleModal"
                    class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent transition-colors hover:bg-accent-hover"
                >
                    <x-heroicon-o-plus class="size-4" aria-hidden="true" />
                    New Role
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @error('roleAction')
        <p role="alert" class="rounded-md border border-danger/35 bg-danger-soft px-4 py-2.5 text-sm text-danger-ink">{{ $message }}</p>
    @enderror

    <section aria-labelledby="roles-heading" class="flex flex-col gap-3">
        <div class="flex items-baseline justify-between gap-3">
            <h2 id="roles-heading" class="text-sm font-bold text-fg">Roles</h2>
            <span class="text-xs text-muted">{{ \App\Support\Plural::count($roles->count(), 'role') }}</span>
        </div>

        <ul class="grid grid-cols-[repeat(auto-fill,minmax(230px,1fr))] gap-3.5">
            @foreach ($roles as $role)
                @php
                    $roleBadge = $rolePresentation[$role->slug] ?? ['label' => $role->name, 'class' => 'bg-surface-strong text-fg-soft'];
                @endphp
                <li wire:key="role-card-{{ $role->id }}" class="flex min-w-0 flex-col rounded-lg border border-border bg-surface p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="break-words text-sm font-bold text-fg">{{ $role->name }}</h3>
                            <p class="mt-0.5 line-clamp-2 text-xs text-muted">{{ $role->description ?: 'No description yet.' }}</p>
                        </div>
                        @if ($role->isSystemRole())
                            <span title="Built-in role. It cannot be deleted." class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full bg-surface-strong px-2 py-0.5 text-[10.5px] font-bold text-fg-soft">
                                <x-heroicon-o-lock-closed class="size-3" aria-hidden="true" />
                                System role
                            </span>
                        @else
                            <span @class(['inline-block shrink-0 whitespace-nowrap rounded px-2 py-0.5 text-[10.5px] font-bold', $roleBadge['class']])>Custom</span>
                        @endif
                    </div>

                    <dl class="mt-3 flex gap-6">
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-muted">Users</dt>
                            <dd class="mt-0.5 text-lg font-extrabold tabular-nums text-fg">
                                <a href="{{ route('staff.directory', ['role' => $role->id]) }}" class="hover:text-accent-ink hover:underline">
                                    {{ $role->users_count }}<span class="sr-only"> {{ \Illuminate\Support\Str::plural('user', $role->users_count) }} with this role</span>
                                </a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-muted">Modules</dt>
                            <dd class="mt-0.5 text-lg font-extrabold tabular-nums text-fg">
                                {{ $role->accessible_modules_count }} <span class="text-xs font-semibold text-muted">of {{ $modules->count() }}</span>
                            </dd>
                        </div>
                    </dl>

                    @if ($canManageRoles && $role->slug !== 'administrator')
                        <div class="mt-3 flex items-center gap-4 border-t border-border pt-3 text-xs">
                            @if ($role->slug !== 'administrator')
                                <button type="button" wire:click="openEditRoleModal({{ $role->id }})" aria-label="Rename {{ $role->name }}" class="font-semibold text-accent-ink hover:text-accent-ink">
                                    Rename
                                </button>
                            @endif
                            @unless ($role->isSystemRole())
                                <button type="button" id="delete-role-{{ $role->id }}" wire:click="openDeleteRoleModal({{ $role->id }})" aria-label="Delete {{ $role->name }}" class="font-semibold text-danger-ink hover:text-danger-ink">
                                    Delete
                                </button>
                            @endunless
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="module-access-heading" class="isolate overflow-hidden rounded-lg border border-border bg-surface">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-[18px] py-3.5">
            <div>
                <h2 id="module-access-heading" class="text-sm font-bold text-fg">Module access</h2>
                <p class="mt-0.5 text-xs text-muted">
                    @if ($canManageRoles)
                        Choose an access level for each role. Changes are saved together when you select Review &amp; save.
                    @else
                        Access each role has to every module.
                    @endif
                </p>
            </div>
            <ul aria-label="Access levels" class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11.5px] text-fg-soft">
                @foreach ($accessLevels as $accessLevel)
                    <li class="flex items-center gap-1.5">
                        <span @class(['inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-semibold', $levelStyles[$accessLevel]])>
                            <x-dynamic-component :component="'heroicon-o-'.$accessPresentation[$accessLevel]['icon']" class="size-3.5" aria-hidden="true" />
                            {{ $accessPresentation[$accessLevel]['label'] }}
                        </span>
                        {{ $accessPresentation[$accessLevel]['description'] }}
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="relative max-h-[70vh] overflow-auto">
            <table class="w-full min-w-[920px] border-separate border-spacing-0 text-left">
                <caption class="sr-only">Module access by role. Each cell shows whether the role has no access, view-only access, or full access to the module.</caption>
                <thead>
                    <tr>
                        <th scope="col" class="sticky left-0 top-0 z-30 min-w-[240px] border-b border-r border-border bg-surface-muted px-[18px] py-2.5 text-left text-[11px] font-bold uppercase tracking-[0.4px] text-muted">
                            Module
                        </th>
                        @foreach ($roles as $role)
                            <th wire:key="role-{{ $role->id }}" scope="col" class="sticky top-0 z-20 min-w-[136px] border-b border-border bg-surface-muted px-3 py-2.5 text-center">
                                <span class="inline-flex items-center gap-1 whitespace-nowrap text-[12px] font-bold text-fg">
                                    {{ $role->name }}
                                    @if ($role->isSystemRole())
                                        <x-heroicon-o-lock-closed class="size-3 text-muted" aria-hidden="true" />
                                        <span class="sr-only">(system role)</span>
                                    @endif
                                </span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($modules as $module)
                        <tr wire:key="module-{{ $module->id }}" class="group">
                            <th scope="row" class="sticky left-0 z-10 min-w-[240px] border-b border-r border-border bg-surface px-[18px] py-3 text-left group-hover:bg-surface-muted">
                                <span class="block text-[13px] font-semibold text-fg">{{ $module->name }}</span>
                                @if ($module->description)
                                    <span class="mt-0.5 block text-[11.5px] font-normal leading-snug text-muted">{{ $module->description }}</span>
                                @endif
                            </th>
                            @foreach ($roles as $role)
                                @php
                                    $cellKey = $role->id.'-'.$module->id;
                                    $storedLevel = $storedPermissions[$cellKey] ?? null;
                                    $currentLevel = $pendingPermissions[$cellKey] ?? $storedLevel;
                                    $isChanged = array_key_exists($cellKey, $pendingPermissions);
                                    $storedLevelIndex = array_search($storedLevel, $accessLevels, true);
                                @endphp
                                <td wire:key="permission-{{ $cellKey }}" @class([
                                    'border-b border-border px-3 py-2.5 text-center',
                                    'bg-accent-soft' => $isChanged,
                                    'group-hover:bg-surface-muted' => ! $isChanged,
                                ])>
                                    @if ($storedLevel === null)
                                        <span class="text-xs text-muted">—<span class="sr-only">Not configured</span></span>
                                    @elseif ($canManageRoles)
                                        <div class="flex flex-col items-center gap-1">
                                            <div role="radiogroup" aria-label="{{ $role->name }} access to {{ $module->name }}" class="inline-flex gap-0.5 rounded-md border border-border bg-surface p-0.5">
                                                @foreach ($accessLevels as $accessLevelIndex => $accessLevel)
                                                    @php
                                                        $isSelected = $currentLevel === $accessLevel;
                                                        $isLocked = $role->slug === 'administrator' && $accessLevelIndex < $storedLevelIndex;
                                                    @endphp
                                                    <button
                                                        type="button"
                                                        role="radio"
                                                        aria-checked="{{ $isSelected ? 'true' : 'false' }}"
                                                        title="{{ $isLocked ? 'Administrator access cannot be reduced' : $accessPresentation[$accessLevel]['description'] }}"
                                                        wire:click="setPermission({{ $role->id }}, {{ $module->id }}, '{{ $accessLevel }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="setPermission"
                                                        @disabled($isLocked)
                                                        @class([
                                                            'inline-flex items-center gap-1 rounded px-1.5 py-1 text-[11px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-35',
                                                            $levelStyles[$accessLevel] => $isSelected,
                                                            'text-muted hover:bg-surface-strong hover:text-fg-soft' => ! $isSelected,
                                                        ])
                                                    >
                                                        <x-dynamic-component :component="'heroicon-o-'.$accessPresentation[$accessLevel]['icon']" class="size-3.5" aria-hidden="true" />
                                                        <span @class(['sr-only' => ! $isSelected])>{{ $accessPresentation[$accessLevel]['label'] }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                            @if ($isChanged)
                                                <span class="inline-flex items-center gap-1 whitespace-nowrap text-[10.5px] font-semibold text-accent-ink">
                                                    <span aria-hidden="true" class="size-1.5 rounded-full bg-accent"></span>
                                                    Unsaved · was {{ $accessPresentation[$storedLevel]['label'] }}
                                                </span>
                                            @endif
                                        </div>
                                    @else
                                        <span @class(['inline-flex items-center gap-1 rounded px-2 py-0.5 text-[11px] font-semibold', $levelStyles[$storedLevel]])>
                                            <x-dynamic-component :component="'heroicon-o-'.$accessPresentation[$storedLevel]['icon']" class="size-3.5" aria-hidden="true" />
                                            {{ $accessPresentation[$storedLevel]['label'] }}
                                        </span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($canManageRoles && $pendingChangeCount > 0)
        <div role="region" aria-label="Unsaved permission changes" class="sticky bottom-4 z-20 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-accent/35 bg-surface px-4 py-3 shadow-lg">
            <p class="flex items-center gap-2 text-sm font-semibold text-fg">
                <span aria-hidden="true" class="size-2 rounded-full bg-accent"></span>
                {{ \App\Support\Plural::count($pendingChangeCount, 'change') }} not saved
            </p>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="discardPermissionChanges" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                    Discard
                </button>
                <button type="button" wire:click="openSaveConfirmation" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">
                    Review &amp; save
                </button>
            </div>
        </div>
    @endif

    @if ($showSaveConfirmation && $pendingChangeSummary !== [])
        <x-modal-dialog id="save-permissions" title="Save permission changes?" close="closeSaveConfirmation" max-width="max-w-[520px]">
            <p class="text-sm text-fg-soft">These changes apply to everyone with the role from their next page load.</p>
            <ul aria-label="Changes to save" class="max-h-72 divide-y divide-border overflow-y-auto rounded-md border border-border">
                @foreach ($pendingChangeSummary as $change)
                    <li class="flex items-center justify-between gap-3 px-3 py-2 text-[13px]">
                        <span class="min-w-0">
                            <span class="font-semibold text-fg">{{ $change['role'] }}</span>
                            <span class="text-muted">· {{ $change['module'] }}</span>
                        </span>
                        <span class="whitespace-nowrap text-fg-soft">
                            {{ $change['from'] }} <span aria-hidden="true">→</span><span class="sr-only">to</span> <span class="font-semibold">{{ $change['to'] }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>

            <x-slot:footer>
                <button type="button" wire:click="closeSaveConfirmation" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                    Keep editing
                </button>
                <button type="button" wire:click="savePermissions" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">
                    Save {{ \App\Support\Plural::count(count($pendingChangeSummary), 'change') }}
                </button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif

    @if ($showRoleModal)
        <div class="fixed inset-0 z-30 flex items-center justify-center bg-overlay p-4" role="presentation">
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="role-modal-heading"
                class="w-full max-w-md rounded-lg bg-surface p-5 shadow-xl"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="role-modal-heading" class="text-base font-bold text-fg">
                            {{ $editingRoleId === null ? 'New Role' : 'Rename Role' }}
                        </h2>
                        <p class="mt-1 text-sm text-muted">Set a role name and optional description.</p>
                    </div>
                    <button
                        type="button"
                        wire:click="closeRoleModal"
                        aria-label="Close"
                        class="rounded px-2 py-1 text-muted hover:bg-surface-strong hover:text-fg"
                    >×</button>
                </div>

                <form wire:submit="saveRole" class="mt-5 flex flex-col gap-4">
                    <div>
                        <label for="role-name" class="mb-1 block text-sm font-medium text-fg-soft">Name</label>
                        <input
                            id="role-name"
                            type="text"
                            wire:model="name"
                            required
                            maxlength="255"
                            class="w-full rounded-md border border-border-strong px-3 py-2 text-sm text-fg focus:border-accent focus:outline-none focus:ring-2 focus:ring-focus/20"
                        >
                        @error('name')
                            <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="role-description" class="mb-1 block text-sm font-medium text-fg-soft">Description <span class="font-normal text-muted">(optional)</span></label>
                        <textarea
                            id="role-description"
                            wire:model="description"
                            maxlength="1000"
                            rows="3"
                            class="w-full rounded-md border border-border-strong px-3 py-2 text-sm text-fg focus:border-accent focus:outline-none focus:ring-2 focus:ring-focus/20"
                        ></textarea>
                        @error('description')
                            <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            wire:click="closeRoleModal"
                            class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-medium text-fg-soft hover:bg-surface-muted"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="rounded-md bg-accent px-3 py-2 text-sm font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60"
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
            class="fixed inset-0 z-30 flex items-center justify-center bg-overlay p-4"
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
                class="w-full max-w-md rounded-lg bg-surface p-5 shadow-xl"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        @if ($deletingRole->isSystemRole())
                            <h2 id="delete-role-heading" class="text-base font-bold text-fg">Protected system role</h2>
                        @elseif ($deletingRole->users_count > 0)
                            <h2 id="delete-role-heading" class="text-base font-bold text-fg">Role cannot be deleted</h2>
                        @else
                            <h2 id="delete-role-heading" class="text-base font-bold text-fg">Delete role '{{ $deletingRole->name }}'?</h2>
                        @endif
                    </div>
                </div>

                @if ($deletingRole->isSystemRole())
                    <p class="mt-4 text-sm text-fg-soft">'{{ $deletingRole->name }}' is a system role and cannot be deleted.</p>
                @elseif ($deletingRole->users_count > 0)
                    <p class="mt-4 text-sm text-fg-soft">{{ \App\Support\Plural::count($deletingRole->users_count, 'user') }} still {{ $deletingRole->users_count === 1 ? 'has' : 'have' }} the role '{{ $deletingRole->name }}'. Reassign them before deleting it.</p>
                    <ul class="mt-3 list-inside list-disc text-sm text-fg-soft">
                        @foreach ($deletingRoleUsers as $userName)
                            <li>{{ $userName }}</li>
                        @endforeach
                        @if ($deletingRole->users_count > $deletingRoleUsers->count())
                            <li>and {{ $deletingRole->users_count - $deletingRoleUsers->count() }} more</li>
                        @endif
                    </ul>
                    <a href="{{ $deletingRoleUsersUrl }}" class="mt-3 inline-block text-sm font-semibold text-accent-ink hover:text-accent-ink hover:underline">View users</a>
                @else
                    <div class="mt-4 flex flex-col gap-2 text-sm text-fg-soft">
                        <p>Users with this role: {{ $deletingRole->users_count }}</p>
                        <p>Modules with access above 'none': {{ $deletingRole->accessible_modules_count }}</p>
                        <p>This cannot be undone.</p>
                    </div>
                @endif

                @if ($deleteRoleError)
                    <p role="alert" class="mt-4 rounded-md border border-danger/35 bg-danger-soft px-3 py-2 text-sm text-danger-ink">{{ $deleteRoleError }}</p>
                @endif

                <div class="mt-5 flex justify-end gap-2">
                    @if (! $deletingRole->isSystemRole() && $deletingRole->users_count === 0)
                        <button
                            x-ref="cancelButton"
                            type="button"
                            wire:click="closeDeleteRoleModal"
                            class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-medium text-fg-soft hover:bg-surface-muted"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            wire:click="deleteRole"
                            wire:loading.attr="disabled"
                            class="rounded-md bg-danger px-3 py-2 text-sm font-semibold text-on-danger hover:bg-danger-hover disabled:opacity-60"
                        >
                            Delete role
                        </button>
                    @else
                        <button
                            x-ref="closeButton"
                            type="button"
                            wire:click="closeDeleteRoleModal"
                            class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-medium text-fg-soft hover:bg-surface-muted"
                        >
                            Close
                        </button>
                    @endif
                </div>
            </section>
        </div>
    @endif
</div>
