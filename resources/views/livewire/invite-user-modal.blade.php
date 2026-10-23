<div>
    @if ($isOpen)
    <div wire:click.self="closeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/45 p-4">
        <div role="dialog" aria-modal="true" aria-labelledby="invite-modal-heading" class="w-full max-w-[440px] overflow-hidden rounded-[10px] bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 id="invite-modal-heading" class="text-[15px] font-bold text-gray-900">Invite User</h3>
                <button type="button" wire:click="closeModal" aria-label="Close invite user dialog" class="text-base text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <div class="px-5 py-[18px]">
                @if ($temporaryPassword !== null)
                    <div class="mb-3.5 rounded-md border border-amber-200 bg-amber-50 px-3.5 py-3">
                        <p class="text-[12.5px] font-bold text-amber-900">
                            @if ($emailSent)
                                Invitation email sent to {{ $invitedUserEmail }}
                            @else
                                Temporary password for {{ $invitedUserEmail }}
                            @endif
                        </p>
                        @error('emailDelivery') <p class="mt-1 text-[11px] text-red-700">{{ $message }}</p> @enderror
                        <div class="mt-2 flex items-center gap-2">
                            <input id="invite-temp-password" type="text" readonly value="{{ $temporaryPassword }}" aria-label="Temporary password" class="w-full rounded-md border border-amber-300 bg-white px-2.5 py-2 font-mono text-[13px] text-gray-800">
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('invite-temp-password').value)" class="shrink-0 rounded-md border border-amber-300 bg-white px-3 py-2 text-[13px] font-semibold text-amber-900 hover:bg-amber-100">Copy</button>
                        </div>
                        <p class="mt-2 text-[11px] font-medium text-amber-800">This temporary password is shown once. The user must change it on first login.</p>
                    </div>
                @else
                    <div class="mb-3.5">
                        <label for="invite-name" class="mb-1.5 block text-xs font-semibold text-gray-600">Full name</label>
                        <input id="invite-name" wire:model="name" placeholder="e.g. Amina Njoroge" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-[13px] text-gray-800 placeholder:text-gray-400">
                        @error('name') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="mb-3.5">
                        <label for="invite-email" class="mb-1.5 block text-xs font-semibold text-gray-600">Work email</label>
                        <input id="invite-email" type="email" wire:model="email" placeholder="you@company.com" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-[13px] text-gray-800 placeholder:text-gray-400">
                        @error('email') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="invite-role" class="mb-1.5 block text-xs font-semibold text-gray-600">Role</label>
                        <select id="invite-role" wire:model.live="roleId" class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-[13px] text-gray-800">
                            <option value="">Select role…</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                        @error('roleId') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <fieldset class="mt-4">
                        <legend class="mb-1.5 block text-xs font-semibold text-gray-600">Temporary password</legend>
                        <div class="flex gap-4 text-[13px] text-gray-700">
                            <label class="inline-flex items-center gap-1.5">
                                <input type="radio" wire:model.live="passwordMode" value="generate">
                                Generate automatically
                            </label>
                            <label class="inline-flex items-center gap-1.5">
                                <input type="radio" wire:model.live="passwordMode" value="custom">
                                Set password
                            </label>
                        </div>
                        @error('passwordMode') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                        @if ($passwordMode === 'custom')
                            <div class="mt-3 grid gap-3">
                                <div>
                                    <label for="invite-custom-password" class="mb-1.5 block text-xs font-semibold text-gray-600">Password (at least 12 characters)</label>
                                    <input id="invite-custom-password" type="password" wire:model="customPassword" autocomplete="new-password" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-[13px] text-gray-800">
                                    @error('customPassword') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="invite-custom-password-confirmation" class="mb-1.5 block text-xs font-semibold text-gray-600">Confirm password</label>
                                    <input id="invite-custom-password-confirmation" type="password" wire:model="customPassword_confirmation" autocomplete="new-password" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-[13px] text-gray-800">
                                </div>
                            </div>
                        @endif
                    </fieldset>
                @endif
            </div>
            <div class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3.5">
                <button type="button" wire:click="closeModal" class="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">Done</button>
                @if ($temporaryPassword === null)
                    <button type="button" wire:click="saveInvite" wire:loading.attr="disabled" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">Send Invite</button>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
