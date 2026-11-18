@php
    $inputClasses = 'w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-fg disabled:bg-surface-muted disabled:text-muted';
    $rowClasses = 'flex flex-col gap-3 px-[18px] py-4 sm:flex-row sm:items-center sm:justify-between';
    $labelColumnClasses = 'min-w-0 sm:max-w-[52%]';
@endphp

<div class="flex flex-col gap-6" x-data="{ toast: false, toastMessage: '' }" x-on:profile-saved.window="toastMessage = $event.detail.message; toast = true; setTimeout(() => toast = false, 4000)">
    <div x-show="toast" x-transition role="status" style="display: none" class="fixed right-4 top-4 z-40 rounded-md border border-success/35 bg-success-soft px-4 py-2.5 text-sm font-medium text-success-ink shadow-lg">
        <span x-text="toastMessage"></span>
    </div>

    <x-page-header title="My Profile" subtitle="Manage your details, password, sessions, and preferences.">
        <x-slot:actions>
            <a href="{{ route('help') }}" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                <x-heroicon-o-question-mark-circle class="size-4" aria-hidden="true" />
                {{ __('help.centre.title') }}
            </a>
            <button type="button" wire:click="$dispatch('replay-tour')" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                <x-heroicon-o-play-circle class="size-4" aria-hidden="true" />
                {{ __('help.centre.replay_tour') }}
            </button>
        </x-slot:actions>
    </x-page-header>

    @if ($isViewingAs)
        <p role="status" class="rounded-md border border-warning/35 bg-warning-soft px-4 py-2.5 text-sm text-warning-ink">
            You are viewing this profile with View As. It is read-only until you return to your account.
        </p>
    @endif

    <nav aria-label="Profile sections" class="flex flex-wrap gap-1 border-b border-border">
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
                    'border-accent text-accent-ink' => $activeTab === $tab,
                    'border-transparent text-muted hover:text-fg' => $activeTab !== $tab,
                ])
                @if ($activeTab === $tab) aria-current="page" @endif
            >{{ $label }}</button>
        @endforeach
    </nav>

    <section aria-labelledby="profile-panel-heading" class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
        <div class="border-b border-border px-[18px] py-4">
            <h2 id="profile-panel-heading" class="text-[15px] font-bold text-fg">{{ str($activeTab)->headline() }}</h2>
        </div>

        @if ($activeTab === 'details')
            <form wire:submit="saveDetails">
                <div class="divide-y divide-border">
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="profile-name" class="block text-[13px] font-semibold text-fg">Name</label>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <input id="profile-name" type="text" wire:model="name" autocomplete="name" @disabled($isViewingAs) class="{{ $inputClasses }}">
                            @error('name') <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label @if ($canEditEmail) for="profile-email" @endif class="block text-[13px] font-semibold text-fg">Email</label>
                            @unless ($canEditEmail)
                                <p class="mt-1 text-xs text-muted">Ask an administrator to change your email address.</p>
                            @endunless
                        </div>
                        <div class="w-full sm:max-w-sm">
                            @if ($canEditEmail)
                                <input id="profile-email" type="email" wire:model="email" autocomplete="email" @disabled($isViewingAs) class="{{ $inputClasses }}">
                                @error('email') <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                            @else
                                <p class="rounded-md border border-border bg-surface-muted px-3 py-2 text-sm text-fg-soft">{{ $user->email }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <p class="text-[13px] font-semibold text-fg">Role</p>
                        <p class="w-full rounded-md border border-border bg-surface-muted px-3 py-2 text-sm text-fg-soft sm:max-w-sm">{{ $user->role?->name ?? '—' }}</p>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <p class="text-[13px] font-semibold text-fg">Last active</p>
                        <p class="w-full rounded-md border border-border bg-surface-muted px-3 py-2 text-sm text-fg-soft sm:max-w-sm">{{ app(\App\Support\SettingsDate::class)->formatDateTime($user->last_active_at) }}</p>
                    </div>
                </div>
                @unless ($isViewingAs)
                    <div class="flex justify-end border-t border-border bg-surface-muted px-[18px] py-3.5">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">Save details</button>
                    </div>
                @endunless
            </form>
        @elseif ($activeTab === 'password')
            <form wire:submit="changePassword">
                <div class="divide-y divide-border">
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="profile-current-password" class="block text-[13px] font-semibold text-fg">Current password</label>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <input id="profile-current-password" type="password" wire:model="currentPassword" autocomplete="current-password" @disabled($isViewingAs) class="{{ $inputClasses }}">
                            @error('currentPassword') <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="profile-new-password" class="block text-[13px] font-semibold text-fg">New password</label>
                            <p class="mt-1 text-xs text-muted">At least {{ $passwordMinimumLength }} characters. Your other sessions will be signed out.</p>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <input id="profile-new-password" type="password" wire:model="newPassword" autocomplete="new-password" @disabled($isViewingAs) class="{{ $inputClasses }}">
                            @error('newPassword') <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="profile-new-password-confirmation" class="block text-[13px] font-semibold text-fg">Confirm new password</label>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <input id="profile-new-password-confirmation" type="password" wire:model="newPasswordConfirmation" autocomplete="new-password" @disabled($isViewingAs) class="{{ $inputClasses }}">
                            @error('newPasswordConfirmation') <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                @unless ($isViewingAs)
                    <div class="flex justify-end border-t border-border bg-surface-muted px-[18px] py-3.5">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">Change password</button>
                    </div>
                @endunless
            </form>
        @elseif ($activeTab === 'sessions')
            @if (! $tracksSessions)
                <p class="px-[18px] py-8 text-center text-sm text-muted">Session tracking requires the database session driver.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] border-collapse text-left">
                        <caption class="sr-only">Your active sessions with device, IP address, and last activity.</caption>
                        <thead class="bg-surface-muted">
                            <tr>
                                <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Device</th>
                                <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">IP address</th>
                                <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Last active</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sessions as $session)
                                <tr wire:key="session-{{ $session['id'] }}" class="border-b border-border">
                                    <td class="px-[18px] py-3 text-sm text-fg">
                                        {{ $session['browser'] }} on {{ $session['platform'] }}
                                        @if ($session['is_current'])
                                            <span class="ml-1.5 inline-flex rounded-full bg-success-soft px-2 py-0.5 text-xs font-semibold text-success-ink">This device</span>
                                        @endif
                                    </td>
                                    <td class="px-[18px] py-3 font-mono text-xs text-fg-soft">{{ $session['ip_address'] ?? '—' }}</td>
                                    <td class="px-[18px] py-3 text-sm text-fg-soft">{{ $session['is_current'] ? 'Now' : $session['last_active']->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-[18px] py-8 text-center text-sm text-muted">No active sessions found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @unless ($isViewingAs)
                    <div class="flex justify-end border-t border-border bg-surface-muted px-[18px] py-3.5">
                        <button type="button" wire:click="openLogoutSessionsModal" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-medium text-fg-soft hover:bg-surface-muted">Log out other sessions</button>
                    </div>
                @endunless
            @endif
        @else
            <form wire:submit="savePreferences">
                <div class="divide-y divide-border">
                    @foreach ($notificationOptions as $notificationKey => $notificationOption)
                        @php
                            $field = $preferences->fieldFor($notificationKey);
                            $globallyEnabled = $preferences->isGloballyEnabled($notificationKey);
                        @endphp
                        <div class="{{ $rowClasses }}">
                            <div class="{{ $labelColumnClasses }}">
                                <label for="preference-{{ $field }}" class="block text-[13px] font-semibold text-fg">{{ $notificationOption['label'] }}</label>
                                @unless ($globallyEnabled)
                                    <p class="mt-1 text-xs text-muted">Turned off for everyone in Settings.</p>
                                @endunless
                            </div>
                            <div class="w-full sm:max-w-sm">
                                <label class="inline-flex items-center gap-2.5">
                                    <input
                                        id="preference-{{ $field }}"
                                        type="checkbox"
                                        wire:model="notificationPreferences.{{ $field }}"
                                        @disabled($isViewingAs || ! $globallyEnabled)
                                        class="size-4 rounded border-border-strong text-accent-ink focus:ring-focus disabled:opacity-60"
                                    >
                                    <span class="text-sm text-fg-soft">{{ ($notificationPreferences[$field] ?? false) ? 'Enabled' : 'Disabled' }}</span>
                                </label>
                                @error('notifications.'.$field) <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endforeach
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="preference-landing-page" class="block text-[13px] font-semibold text-fg">Default landing page</label>
                            <p class="mt-1 text-xs text-muted">Where you arrive after signing in.</p>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <select id="preference-landing-page" wire:model="landingPage" @disabled($isViewingAs) class="{{ $inputClasses }}">
                                @foreach ($landingPageOptions as $landingPageValue => $landingPageOption)
                                    <option value="{{ $landingPageValue }}">{{ $landingPageOption['label'] }}</option>
                                @endforeach
                            </select>
                            @error('landing_page') <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="preference-page-size" class="block text-[13px] font-semibold text-fg">Table page size</label>
                            <p class="mt-1 text-xs text-muted">Rows per page on paginated tables.</p>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <select id="preference-page-size" wire:model="pageSize" @disabled($isViewingAs) class="{{ $inputClasses }}">
                                <option value="">Page default</option>
                                @foreach ($pageSizeOptions as $pageSizeOption)
                                    <option value="{{ $pageSizeOption }}">{{ $pageSizeOption }} rows</option>
                                @endforeach
                            </select>
                            @error('page_size') <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="preference-theme" class="block text-[13px] font-semibold text-fg">Theme</label>
                            <p class="mt-1 text-xs text-muted">System follows your device's light or dark setting.</p>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <select id="preference-theme" wire:model="theme" @disabled($isViewingAs) class="{{ $inputClasses }}">
                                <option value="">Organisation default ({{ $themeOptions[$organisationAppearance['theme']] }})</option>
                                @foreach ($themeOptions as $themeValue => $themeLabel)
                                    <option value="{{ $themeValue }}">{{ $themeLabel }}</option>
                                @endforeach
                            </select>
                            @error('theme') <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="preference-profile" class="block text-[13px] font-semibold text-fg">Colour profile</label>
                            <p class="mt-1 text-xs text-muted">The palette for the sidebar, buttons and charts. Status colours keep their meaning in every profile.</p>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <select id="preference-profile" wire:model="profile" @disabled($isViewingAs) class="{{ $inputClasses }}">
                                <option value="">Organisation default ({{ $profileOptions[$organisationAppearance['profile']] }})</option>
                                @foreach ($profileOptions as $profileValue => $profileLabel)
                                    <option value="{{ $profileValue }}">{{ $profileLabel }}</option>
                                @endforeach
                            </select>
                            @error('profile') <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $rowClasses }}">
                        <div class="{{ $labelColumnClasses }}">
                            <label for="preference-reduce-motion" class="block text-[13px] font-semibold text-fg">Reduce motion</label>
                            <p class="mt-1 text-xs text-muted">Skip the sign-in transition and turn off animations. Your device's reduce-motion setting is always respected too.</p>
                        </div>
                        <div class="w-full sm:max-w-sm">
                            <label class="inline-flex items-center gap-2.5">
                                <input
                                    id="preference-reduce-motion"
                                    type="checkbox"
                                    wire:model="reduceMotion"
                                    @disabled($isViewingAs)
                                    class="size-4 rounded border-border-strong text-accent-ink focus:ring-focus disabled:opacity-60"
                                >
                                <span class="text-sm text-fg-soft">{{ $reduceMotion ? 'On' : 'Off' }}</span>
                            </label>
                            @error('reduce_motion') <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                @unless ($isViewingAs)
                    <div class="flex justify-end border-t border-border bg-surface-muted px-[18px] py-3.5">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">Save preferences</button>
                    </div>
                @endunless
            </form>
        @endif
    </section>

    @if ($showLogoutSessionsModal)
        <div
            class="fixed inset-0 z-30 flex items-center justify-center bg-overlay p-4"
            role="presentation"
            x-data
            x-init="$nextTick(() => $refs.sessionsPassword.focus())"
            x-on:keydown.escape.window="$wire.closeLogoutSessionsModal()"
        >
            <section role="dialog" aria-modal="true" aria-labelledby="logout-sessions-heading" class="w-full max-w-md rounded-lg bg-surface p-5 shadow-xl">
                <form wire:submit="logoutOtherSessions">
                    <h2 id="logout-sessions-heading" class="text-base font-bold text-fg">Log out other sessions?</h2>
                    <p class="mt-2 text-sm text-fg-soft">Enter your password to sign out of every other browser and device.</p>
                    <label for="sessions-password" class="mt-4 block text-[13px] font-semibold text-fg">Password</label>
                    <input id="sessions-password" x-ref="sessionsPassword" type="password" wire:model="sessionsPassword" autocomplete="current-password" class="{{ $inputClasses }} mt-1.5">
                    @error('sessionsPassword') <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p> @enderror
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" wire:click="closeLogoutSessionsModal" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-medium text-fg-soft hover:bg-surface-muted">Cancel</button>
                        <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-danger px-3 py-2 text-sm font-semibold text-on-danger hover:bg-danger-hover disabled:opacity-60">Log out other sessions</button>
                    </div>
                </form>
            </section>
        </div>
    @endif
</div>
