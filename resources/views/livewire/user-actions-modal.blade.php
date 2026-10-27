<div>
    @if ($isOpen && $target !== null)
    <div wire:click.self="closeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/45 p-4">
        <div role="dialog" aria-modal="true" aria-labelledby="user-action-heading" class="w-full max-w-[440px] overflow-hidden rounded-[10px] bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h3 id="user-action-heading" class="text-[15px] font-bold text-gray-900">
                    @if ($action === 'role')
                        Change role — {{ $target->name }}
                    @elseif ($action === 'suspend')
                        {{ $target->status === 'suspended' ? 'Reactivate' : 'Suspend' }} — {{ $target->name }}
                    @else
                        Reset password — {{ $target->name }}
                    @endif
                </h3>
                <button type="button" wire:click="closeModal" aria-label="Close user action dialog" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"><x-heroicon-o-x-mark class="size-5" aria-hidden="true" /></button>
            </div>
            <div class="px-5 py-[18px]">
                @if ($action === 'role')
                    <div>
                        <label for="user-action-role" class="mb-1.5 block text-xs font-semibold text-gray-600">Role</label>
                        <select id="user-action-role" wire:model.live="roleId" class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-[13px] text-gray-800">
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                        @error('roleId') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                    </div>
                @elseif ($action === 'suspend')
                    <p class="text-[13px] text-gray-700">
                        @if ($target->status === 'suspended')
                            Reactivate {{ $target->name }} ({{ $target->email }})? They will be able to sign in again.
                        @else
                            Suspend {{ $target->name }} ({{ $target->email }})? They will be signed out everywhere and unable to sign in.
                        @endif
                    </p>
                    @error('suspend') <p class="mt-1 text-[11px] text-red-500">{{ $message }}</p> @enderror
                @else
                    @if ($temporaryPassword !== null)
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-3.5 py-3">
                            <p class="text-[12.5px] font-bold text-amber-900">Temporary password for {{ $target->email }}</p>
                            <div class="mt-2 flex items-center gap-2">
                                <input id="user-action-temp-password" type="text" readonly value="{{ $temporaryPassword }}" aria-label="Temporary password" class="w-full rounded-md border border-amber-300 bg-white px-2.5 py-2 font-mono text-[13px] text-gray-800">
                                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('user-action-temp-password').value)" class="shrink-0 rounded-md border border-amber-300 bg-white px-3 py-2 text-[13px] font-semibold text-amber-900 hover:bg-amber-100">Copy</button>
                            </div>
                            <p class="mt-2 text-[11px] font-medium text-amber-800">Share it with the user now — it can't be shown again. They must set a new password on next sign-in.</p>
                        </div>
                    @else
                        <p class="text-[13px] text-gray-700">Generate a new temporary password for {{ $target->name }} ({{ $target->email }})? They will be signed out everywhere and must set a new password on next sign-in.</p>
                    @endif
                @endif
            </div>
            <div class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3.5">
                <button type="button" wire:click="closeModal" class="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                @if ($action === 'role')
                    <button type="button" wire:click="saveRole" wire:loading.attr="disabled" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">Save Role</button>
                @elseif ($action === 'suspend')
                    <button type="button" wire:click="confirmSuspend" wire:loading.attr="disabled" class="rounded-md {{ $target->status === 'suspended' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700' }} px-3.5 py-2 text-[13px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60">{{ $target->status === 'suspended' ? 'Reactivate' : 'Suspend' }}</button>
                @elseif ($temporaryPassword === null)
                    <button type="button" wire:click="generateResetPassword" wire:loading.attr="disabled" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">Generate Password</button>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
