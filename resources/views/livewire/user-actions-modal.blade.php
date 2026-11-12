<div>
    @if ($isOpen && $target !== null)
    <div wire:click.self="closeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-4">
        <div role="dialog" aria-modal="true" aria-labelledby="user-action-heading" class="w-full max-w-[440px] overflow-hidden rounded-[10px] bg-surface shadow-xl">
            <div class="flex items-center justify-between border-b border-border px-5 py-4">
                <h3 id="user-action-heading" class="text-[15px] font-bold text-fg">
                    @if ($action === 'role')
                        Change role — {{ $target->name }}
                    @elseif ($action === 'suspend')
                        {{ $target->status === 'suspended' ? 'Reactivate' : 'Suspend' }} — {{ $target->name }}
                    @else
                        Reset password — {{ $target->name }}
                    @endif
                </h3>
                <button type="button" wire:click="closeModal" aria-label="Close user action dialog" class="rounded-md p-1 text-muted hover:bg-surface-strong hover:text-fg-soft"><x-heroicon-o-x-mark class="size-5" aria-hidden="true" /></button>
            </div>
            <div class="px-5 py-[18px]">
                @if ($action === 'role')
                    <div>
                        <label for="user-action-role" class="mb-1.5 block text-xs font-semibold text-fg-soft">Role</label>
                        <select id="user-action-role" wire:model.live="roleId" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                        @error('roleId') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                    </div>
                @elseif ($action === 'suspend')
                    <p class="text-[13px] text-fg-soft">
                        @if ($target->status === 'suspended')
                            Reactivate {{ $target->name }} ({{ $target->email }})? They will be able to sign in again.
                        @else
                            Suspend {{ $target->name }} ({{ $target->email }})? They will be signed out everywhere and unable to sign in.
                        @endif
                    </p>
                    @error('suspend') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                    @error('reset') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                @else
                    @if ($temporaryPassword !== null)
                        <div class="rounded-md border border-warning/35 bg-warning-soft px-3.5 py-3">
                            <p class="text-[12.5px] font-bold text-warning-ink">Temporary password for {{ $target->email }}</p>
                            <div class="mt-2 flex items-center gap-2">
                                <input id="user-action-temp-password" type="text" readonly value="{{ $temporaryPassword }}" aria-label="Temporary password" class="w-full rounded-md border border-warning/35 bg-surface px-2.5 py-2 font-mono text-[13px] text-fg">
                                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('user-action-temp-password').value)" class="shrink-0 rounded-md border border-warning/35 bg-surface px-3 py-2 text-[13px] font-semibold text-warning-ink hover:bg-warning-soft">Copy</button>
                            </div>
                            <p class="mt-2 text-[11px] font-medium text-warning-ink">Share it with the user now — it can't be shown again. They must set a new password on next sign-in.</p>
                        </div>
                    @else
                        <p class="text-[13px] text-fg-soft">Generate a new temporary password for {{ $target->name }} ({{ $target->email }})? They will be signed out everywhere and must set a new password on next sign-in.</p>
                    @endif
                @endif
            </div>
            <div class="flex justify-end gap-2 border-t border-border bg-surface-muted px-5 py-3.5">
                <button type="button" wire:click="closeModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Cancel</button>
                @if ($action === 'role')
                    <button type="button" wire:click="saveRole" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60">Save Role</button>
                @elseif ($action === 'suspend')
                    <button type="button" wire:click="confirmSuspend" wire:loading.attr="disabled" class="rounded-md {{ $target->status === 'suspended' ? 'bg-success hover:bg-success-hover' : 'bg-danger hover:bg-danger-hover' }} px-3.5 py-2 text-[13px] font-semibold text-on-success disabled:cursor-not-allowed disabled:opacity-60">{{ $target->status === 'suspended' ? 'Reactivate' : 'Suspend' }}</button>
                @elseif ($temporaryPassword === null)
                    <button type="button" wire:click="generateResetPassword" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60">Generate Password</button>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
