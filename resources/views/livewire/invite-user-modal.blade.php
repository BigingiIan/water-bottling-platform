<div>
    @if ($isOpen)
    <div wire:click.self="closeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-4">
        <div role="dialog" aria-modal="true" aria-labelledby="invite-modal-heading" class="w-full max-w-[440px] overflow-hidden rounded-[10px] bg-surface shadow-xl">
            <div class="flex items-center justify-between border-b border-border px-5 py-4">
                <h3 id="invite-modal-heading" class="text-[15px] font-bold text-fg">Invite User</h3>
                <button type="button" wire:click="closeModal" aria-label="Close invite user dialog" class="rounded-md p-1 text-muted hover:bg-surface-strong hover:text-fg-soft"><x-heroicon-o-x-mark class="size-5" aria-hidden="true" /></button>
            </div>
            <div class="px-5 py-[18px]">
                @if ($temporaryPassword !== null)
                    <div class="mb-3.5 rounded-md border border-warning/35 bg-warning-soft px-3.5 py-3">
                        <p class="text-[12.5px] font-bold text-warning-ink">
                            @if ($emailSent)
                                Invitation email sent to {{ $invitedUserEmail }}
                            @else
                                Temporary password for {{ $invitedUserEmail }}
                            @endif
                        </p>
                        @error('emailDelivery') <p class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                        <div class="mt-2 flex items-center gap-2">
                            <input id="invite-temp-password" type="text" readonly value="{{ $temporaryPassword }}" aria-label="Temporary password" class="w-full rounded-md border border-warning/35 bg-surface px-2.5 py-2 font-mono text-[13px] text-fg">
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('invite-temp-password').value)" class="shrink-0 rounded-md border border-warning/35 bg-surface px-3 py-2 text-[13px] font-semibold text-warning-ink hover:bg-warning-soft">Copy</button>
                        </div>
                        <p class="mt-2 text-[11px] font-medium text-warning-ink">This temporary password is shown once. The user must change it on first login.</p>
                    </div>
                @else
                    <div class="mb-3.5">
                        <label for="invite-name" class="mb-1.5 block text-xs font-semibold text-fg-soft">Full name</label>
                        <input id="invite-name" wire:model="name" placeholder="e.g. Amina Njoroge" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg placeholder:text-muted">
                        @error('name') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="mb-3.5">
                        <label for="invite-email" class="mb-1.5 block text-xs font-semibold text-fg-soft">Work email</label>
                        <input id="invite-email" type="email" wire:model="email" placeholder="you@company.com" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg placeholder:text-muted">
                        @error('email') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="invite-role" class="mb-1.5 block text-xs font-semibold text-fg-soft">Role</label>
                        <select id="invite-role" wire:model.live="roleId" class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg">
                            <option value="">Select role…</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                        @error('roleId') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                    </div>
                    <fieldset class="mt-4">
                        <legend class="mb-1.5 block text-xs font-semibold text-fg-soft">Temporary password</legend>
                        <div class="flex gap-4 text-[13px] text-fg-soft">
                            <label class="inline-flex items-center gap-1.5">
                                <input type="radio" wire:model.live="passwordMode" value="generate">
                                Generate automatically
                            </label>
                            <label class="inline-flex items-center gap-1.5">
                                <input type="radio" wire:model.live="passwordMode" value="custom">
                                Set password
                            </label>
                        </div>
                        @error('passwordMode') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                        @if ($passwordMode === 'custom')
                            <div class="mt-3 grid gap-3">
                                <div>
                                    <label for="invite-custom-password" class="mb-1.5 block text-xs font-semibold text-fg-soft">Password (at least 12 characters)</label>
                                    <input id="invite-custom-password" type="password" wire:model="customPassword" autocomplete="new-password" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                                    @error('customPassword') <p class="mt-1 text-[11px] text-danger">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="invite-custom-password-confirmation" class="mb-1.5 block text-xs font-semibold text-fg-soft">Confirm password</label>
                                    <input id="invite-custom-password-confirmation" type="password" wire:model="customPassword_confirmation" autocomplete="new-password" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                                </div>
                            </div>
                        @endif
                    </fieldset>
                @endif
            </div>
            <div class="flex justify-end gap-2 border-t border-border bg-surface-muted px-5 py-3.5">
                <button type="button" wire:click="closeModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Done</button>
                @if ($temporaryPassword === null)
                    <button type="button" wire:click="saveInvite" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60">Send Invite</button>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
