@php
    $inputClasses = 'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 disabled:bg-gray-50 disabled:text-gray-500';
    $rowClasses = 'flex flex-col gap-3 px-[18px] py-4 sm:flex-row sm:items-center sm:justify-between';
    $labelColumnClasses = 'min-w-0 sm:max-w-[52%]';
@endphp

<div class="flex flex-col gap-6" x-data="{ toast: false, toastMessage: '' }" x-on:profile-saved.window="toastMessage = $event.detail.message; toast = true; setTimeout(() => toast = false, 4000)">
    <div x-show="toast" x-transition role="status" style="display: none" class="fixed right-4 top-4 z-40 rounded-md border border-green-200 bg-green-50 px-4 py-2.5 text-sm font-medium text-green-800 shadow-lg">
        <span x-text="toastMessage"></span>
    </div>

    <x-page-header title="My Profile" subtitle="Manage your details, password, sessions, and preferences." />

    @if ($isViewingAs)
        <p role="status" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-950">
            You are viewing this profile with View As. It is read-only until you return to your account.
        </p>
    @endif

    <nav aria-label="Profile sections" class="flex flex-wrap gap-1 border-b border-gray-200">
        @foreach ([
            'details' => 'Details',
            'password' => 'Password',
            'sessions' => 'Sessions',
            'preferences' => 'Preferences',
        ] as $tab => $label)
            <button
                type="button"
                wire:click="$set('activeTab', '{{ $tab }}')"
                @class([
                    'border-b-2 px-3 py-2.5 text-[13px] font-semibold',
                    'border-blue-600 text-blue-700' => $activeTab === $tab,
                    'border-transparent text-gray-500 hover:text-gray-800' => $activeTab !== $tab,
                ])
                @if ($activeTab === $tab) aria-current="page" @endif
            >{{ $label }}</button>
        @endforeach
    </nav>

    <section aria-labelledby="profile-panel-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-[18px] py-4">
            <h2 id="profile-panel-heading" class="text-[15px] font-bold text-gray-900">{{ str($activeTab)->headline() }}</h2>
        </div>

        @if ($activeTab === 'details')
            <form wire:submit="saveDetails">
                <div class="divide-y divide-gray-100">
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="profile-name" class="block text-[13px] font-semibold text-gray-800">Name</label>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <input id="profile-name" type="text" wire:model="name" autocomplete="name" @disabled($isViewingAs) class="{{ $inputClasses }}">
                            @error('name') <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label @if ($canEditEmail) for="profile-email" @endif class="block text-[13px] font-semibold text-gray-800">Email</label>
                            @unless ($canEditEmail)
                                <p class="mt-1 text-xs text-gray-500">Ask an administrator to change your email address.</p>
                            @endunless
                        </div>
                        <div class="w-full sm:max-w-sm">
                            @if ($canEditEmail)
                                <input id="profile-email" type="email" wire:model="email" autocomplete="email" @disabled($isViewingAs) class="{{ $inputClasses }}">
                                @error('email') <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                            @else
                                <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">{{ $user->email }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <p class="text-[13px] font-semibold text-gray-800">Role</p>
                        <p class="w-full rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600 sm:max-w-sm">{{ $user->role?->name ?? '—' }}</p>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <p class="text-[13px] font-semibold text-gray-800">Last active</p>
                        <p class="w-full rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600 sm:max-w-sm">{{ app(\App\Support\SettingsDate::class)->formatDateTime($user->last_active_at) }}</p>
                    </div>
                </div>
                @unless ($isViewingAs)
                    <div class="flex justify-end border-t border-gray-100 bg-gray-50 px-[18px] py-3.5">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:opacity-60">Save details</button>
                    </div>
                @endunless
            </form>
        @elseif ($activeTab === 'password')
            <form wire:submit="changePassword">
                <div class="divide-y divide-gray-100">
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="profile-current-password" class="block text-[13px] font-semibold text-gray-800">Current password</label>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <input id="profile-current-password" type="password" wire:model="currentPassword" autocomplete="current-password" @disabled($isViewingAs) class="{{ $inputClasses }}">
                            @error('currentPassword') <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="profile-new-password" class="block text-[13px] font-semibold text-gray-800">New password</label>
                            <p class="mt-1 text-xs text-gray-500">At least {{ $passwordMinimumLength }} characters. Your other sessions will be signed out.</p>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <input id="profile-new-password" type="password" wire:model="newPassword" autocomplete="new-password" @disabled($isViewingAs) class="{{ $inputClasses }}">
                            @error('newPassword') <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="profile-new-password-confirmation" class="block text-[13px] font-semibold text-gray-800">Confirm new password</label>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <input id="profile-new-password-confirmation" type="password" wire:model="newPasswordConfirmation" autocomplete="new-password" @disabled($isViewingAs) class="{{ $inputClasses }}">
                            @error('newPasswordConfirmation') <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                @unless ($isViewingAs)
                    <div class="flex justify-end border-t border-gray-100 bg-gray-50 px-[18px] py-3.5">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:opacity-60">Change password</button>
                    </div>
                @endunless
            </form>
        @elseif ($activeTab === 'sessions')
            @if (! $tracksSessions)
                <p class="px-[18px] py-8 text-center text-sm text-gray-500">Session tracking requires the database session driver.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] border-collapse text-left">
                        <caption class="sr-only">Your active sessions with device, IP address, and last activity.</caption>
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Device</th>
                                <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">IP address</th>
                                <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Last active</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sessions as $session)
                                <tr wire:key="session-{{ $session['id'] }}" class="border-b border-gray-100">
                                    <td class="px-[18px] py-3 text-sm text-gray-800">
                                        {{ $session['browser'] }} on {{ $session['platform'] }}
                                        @if ($session['is_current'])
                                            <span class="ml-1.5 inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">This device</span>
                                        @endif
                                    </td>
                                    <td class="px-[18px] py-3 font-mono text-xs text-gray-700">{{ $session['ip_address'] ?? '—' }}</td>
                                    <td class="px-[18px] py-3 text-sm text-gray-600">{{ $session['is_current'] ? 'Now' : $session['last_active']->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-[18px] py-8 text-center text-sm text-gray-500">No active sessions found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @unless ($isViewingAs)
                    <div class="flex justify-end border-t border-gray-100 bg-gray-50 px-[18px] py-3.5">
                        <button type="button" wire:click="openLogoutSessionsModal" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Log out other sessions</button>
                    </div>
                @endunless
            @endif
        @else
            <form wire:submit="savePreferences">
                <div class="divide-y divide-gray-100">
                    @foreach ($notificationOptions as $notificationKey => $notificationOption)
                        @php
                            $field = $preferences->fieldFor($notificationKey);
                            $globallyEnabled = $preferences->isGloballyEnabled($notificationKey);
                        @endphp
                        <div class="{{ $rowClasses }}">
                            <div class="{{ $labelColumnClasses }}">
                                <label for="preference-{{ $field }}" class="block text-[13px] font-semibold text-gray-800">{{ $notificationOption['label'] }}</label>
                                @unless ($globallyEnabled)
                                    <p class="mt-1 text-xs text-gray-500">Turned off for everyone in Settings.</p>
                                @endunless
                            </div>
                            <div class="w-full sm:max-w-sm">
                                <label class="inline-flex items-center gap-2.5">
                                    <input
                                        id="preference-{{ $field }}"
                                        type="checkbox"
                                        wire:model="notificationPreferences.{{ $field }}"
                                        @disabled($isViewingAs || ! $globallyEnabled)
                                        class="size-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:opacity-60"
                                    >
                                    <span class="text-sm text-gray-700">{{ ($notificationPreferences[$field] ?? false) ? 'Enabled' : 'Disabled' }}</span>
                                </label>
                                @error('notifications.'.$field) <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endforeach
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="preference-landing-page" class="block text-[13px] font-semibold text-gray-800">Default landing page</label>
                            <p class="mt-1 text-xs text-gray-500">Where you arrive after signing in.</p>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <select id="preference-landing-page" wire:model="landingPage" @disabled($isViewingAs) class="{{ $inputClasses }}">
                                @foreach ($landingPageOptions as $landingPageValue => $landingPageOption)
                                    <option value="{{ $landingPageValue }}">{{ $landingPageOption['label'] }}</option>
                                @endforeach
                            </select>
                            @error('landing_page') <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="preference-page-size" class="block text-[13px] font-semibold text-gray-800">Table page size</label>
                            <p class="mt-1 text-xs text-gray-500">Rows per page on paginated tables.</p>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <select id="preference-page-size" wire:model="pageSize" @disabled($isViewingAs) class="{{ $inputClasses }}">
                                <option value="">Page default</option>
                                @foreach ($pageSizeOptions as $pageSizeOption)
                                    <option value="{{ $pageSizeOption }}">{{ $pageSizeOption }} rows</option>
                                @endforeach
                            </select>
                            @error('page_size') <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                @unless ($isViewingAs)
                    <div class="flex justify-end border-t border-gray-100 bg-gray-50 px-[18px] py-3.5">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:opacity-60">Save preferences</button>
                    </div>
                @endunless
            </form>
        @endif
    </section>

    @if ($showLogoutSessionsModal)
        <div
            class="fixed inset-0 z-30 flex items-center justify-center bg-gray-950/40 p-4"
            role="presentation"
            x-data
            x-init="$nextTick(() => $refs.sessionsPassword.focus())"
            x-on:keydown.escape.window="$wire.closeLogoutSessionsModal()"
        >
            <section role="dialog" aria-modal="true" aria-labelledby="logout-sessions-heading" class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <form wire:submit="logoutOtherSessions">
                    <h2 id="logout-sessions-heading" class="text-base font-bold text-gray-900">Log out other sessions?</h2>
                    <p class="mt-2 text-sm text-gray-600">Enter your password to sign out of every other browser and device.</p>
                    <label for="sessions-password" class="mt-4 block text-[13px] font-semibold text-gray-800">Password</label>
                    <input id="sessions-password" x-ref="sessionsPassword" type="password" wire:model="sessionsPassword" autocomplete="current-password" class="{{ $inputClasses }} mt-1.5">
                    @error('sessionsPassword') <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" wire:click="closeLogoutSessionsModal" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                        <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60">Log out other sessions</button>
                    </div>
                </form>
            </section>
        </div>
    @endif
</div>
