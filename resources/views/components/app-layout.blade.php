@props([
    'title' => 'Users & Roles',
    'breadcrumbSection' => 'System',
    'activeItem' => 'Users & Roles',
    'userName' => null,
    'userRole' => null,
    'userInitials' => null,
])

@php
    $authenticatedUser = auth()->user();
    $userName ??= $authenticatedUser?->name ?? 'Guest';
    $userRole ??= $authenticatedUser?->role?->name ?? 'Staff';
    $userInitials ??= collect(explode(' ', $userName))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_substr($part, 0, 1))
        ->implode('');

    $navigationSections = [
        [
            'label' => null,
            'items' => [
                ['label' => 'Dashboard', 'module' => 'dashboard', 'alwaysVisible' => true, 'icon' => '▣', 'href' => route('dashboard')],
            ],
        ],
        [
            'label' => 'Production',
            'items' => [
                ['label' => 'Schedule', 'module' => 'production', 'icon' => '⚙', 'href' => route('production.schedule')],
                ['label' => 'Production Runs', 'module' => 'production', 'icon' => '📦', 'href' => route('production.runs')],
                ['label' => 'History', 'module' => 'production', 'icon' => '🕘', 'href' => route('production.history')],
            ],
        ],
        [
            'label' => 'Inventory',
            'items' => [
                ['label' => 'Stock Overview', 'module' => 'inventory', 'icon' => '▦', 'href' => route('inventory.overview')],
                ['label' => 'Orders', 'module' => 'orders', 'icon' => '🧾', 'href' => '#'],
            ],
        ],
        [
            'label' => 'Analytics',
            'items' => [
                ['label' => 'Forecasting', 'module' => 'forecasting', 'icon' => '📈', 'href' => '#'],
                ['label' => 'Reports', 'module' => 'reports', 'icon' => '📊', 'href' => '#'],
            ],
        ],
        [
            'label' => 'System',
            'items' => [
                ['label' => 'Users & Roles', 'module' => 'users-roles', 'icon' => '🛡', 'href' => route('staff.directory')],
                ['label' => 'Roles & Permissions', 'module' => 'users-roles', 'requiredAccess' => 'full', 'icon' => '🔐', 'href' => route('staff.permissions')],
                ['label' => 'Error Logs', 'module' => 'error-logs', 'icon' => '⚠', 'href' => route('errors.index')],
                ['label' => 'Settings', 'module' => 'settings', 'icon' => '⚙', 'href' => route('settings.index')],
            ],
        ],
    ];

    foreach ($navigationSections as &$navigationSection) {
        $navigationSection['items'] = array_values(array_filter(
            $navigationSection['items'],
            fn (array $navigationItem): bool => ($navigationItem['alwaysVisible'] ?? false)
                || in_array(
                    $navigationItem['module'],
                    ($navigationItem['requiredAccess'] ?? null) === 'full'
                        ? $fullAccessModuleSlugs
                        : $accessibleModuleSlugs,
                    true,
                ),
        ));

        foreach ($navigationSection['items'] as &$navigationItem) {
            $navigationItem['active'] = $navigationItem['label'] === $activeItem;
        }
        unset($navigationItem);
    }
    unset($navigationSection);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }} | {{ config('app.name', 'AquaOps') }}</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-gray-50 text-gray-800 antialiased">
        <div class="flex min-h-screen">
            <aside class="sticky top-0 flex h-screen w-[248px] shrink-0 flex-col bg-navy-950 py-5 text-gray-300">
                <a href="/" class="mb-4 flex items-center gap-2.5 border-b border-white/[0.08] px-5 pb-[22px]">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-blue-500 to-teal-500 text-base" aria-hidden="true">💧</span>
                    <span>
                        <span class="block text-[15px] font-extrabold tracking-[0.2px] text-white">AquaOps</span>
                        <span class="block text-[10.5px] font-medium uppercase tracking-[0.4px] text-gray-400">Operations Console</span>
                    </span>
                </a>

                <nav aria-label="Main navigation" class="flex-1">
                    @foreach ($navigationSections as $navigationSection)
                        @if ($navigationSection['items'])
                            <div class="mb-1 px-3">
                                @if ($navigationSection['label'])
                                <h2 class="px-3 pb-1.5 pt-3.5 text-[10.5px] font-bold uppercase tracking-[0.8px] text-[#5b6b85]">
                                    {{ $navigationSection['label'] }}
                                </h2>
                                @endif

                                @foreach ($navigationSection['items'] as $navigationItem)
                                    <a
                                        href="{{ $navigationItem['href'] }}"
                                        @if ($navigationItem['active']) aria-current="page" @endif
                                        @class([
                                            'relative flex items-center gap-2.5 rounded-md px-3 py-2 text-[13.5px] font-medium text-[#a9b6cc] transition-colors hover:bg-white/5 hover:text-white',
                                            'bg-blue-500/15 text-white before:absolute before:-left-3 before:inset-y-1.5 before:w-[3px] before:rounded-r before:bg-blue-500' => $navigationItem['active'],
                                        ])
                                    >
                                        <span class="w-4 shrink-0 text-center" aria-hidden="true">{{ $navigationItem['icon'] }}</span>
                                        <span>{{ $navigationItem['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </nav>

                <div class="mt-auto flex items-center gap-2.5 border-t border-white/[0.08] px-5 py-3.5">
                    <span class="flex size-[30px] shrink-0 items-center justify-center rounded-full bg-navy-700 text-[11px] font-bold text-gray-300">
                        {{ $userInitials }}
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-[12.5px] font-semibold text-gray-200">{{ $userName }}</span>
                        <span class="block truncate text-[10.5px] text-gray-400">{{ $userRole }}</span>
                    </span>
                </div>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="sticky top-0 z-10 border-b border-gray-200 bg-white">
                    <div class="flex h-[60px] items-center justify-between px-6">
                        <nav aria-label="Breadcrumb" class="flex items-center gap-1.5 text-[13px] text-gray-500">
                            <span>{{ $breadcrumbSection }}</span>
                            <span aria-hidden="true">/</span>
                            <span aria-current="page" class="font-semibold text-gray-800">{{ $title }}</span>
                        </nav>

                        <div class="flex items-center gap-3.5">
                            @if ($canImpersonate)
                                <form method="POST" action="{{ route('impersonation.store') }}" class="flex items-center gap-2">
                                    @csrf
                                    <label for="view-as-user" class="sr-only">View as user</label>
                                    <select id="view-as-user" name="user_id" required class="max-w-48 rounded-md border border-gray-200 bg-white px-2 py-1.5 text-xs text-gray-700">
                                        <option value="" selected disabled>View As…</option>
                                        @foreach ($impersonationUsers as $impersonationUser)
                                            <option value="{{ $impersonationUser->id }}">
                                                {{ $impersonationUser->name }} — {{ $impersonationUser->role->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" @disabled($impersonationUsers->isEmpty()) class="text-xs font-semibold text-blue-700 transition-colors hover:text-blue-900 disabled:cursor-not-allowed disabled:text-gray-400">
                                        View As
                                    </button>
                                </form>
                            @endif

                            <label class="flex w-[220px] items-center gap-2 rounded-md bg-gray-100 px-3 py-[7px] text-gray-400">
                                <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" class="size-4 shrink-0">
                                    <circle cx="8.75" cy="8.75" r="5.75" stroke="currentColor" stroke-width="1.5" />
                                    <path d="m13 13 4 4" stroke="currentColor" stroke-linecap="round" stroke-width="1.5" />
                                </svg>
                                <input type="search" placeholder="Search users, roles…" class="min-w-0 flex-1 bg-transparent text-[13px] text-gray-700 outline-none placeholder:text-gray-400">
                            </label>

                            @auth
                                <livewire:notification-bell />
                            @endauth

                            @auth
                                <div
                                    class="relative"
                                    x-data="{ profileMenuOpen: false }"
                                    x-on:click.outside="profileMenuOpen = false"
                                    x-on:keydown.escape.window="if (profileMenuOpen) { profileMenuOpen = false; $refs.profileMenuButton.focus() }"
                                >
                                    <button
                                        x-ref="profileMenuButton"
                                        type="button"
                                        aria-label="Profile menu for {{ $userName }}"
                                        aria-haspopup="true"
                                        aria-controls="profile-menu"
                                        aria-expanded="false"
                                        x-bind:aria-expanded="profileMenuOpen ? 'true' : 'false'"
                                        x-on:click="profileMenuOpen = ! profileMenuOpen"
                                        @class([
                                            'flex size-[34px] items-center justify-center rounded-full text-[11px] font-bold text-gray-200 ring-offset-2 transition hover:ring-2 hover:ring-blue-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
                                            'bg-amber-700' => $isImpersonating,
                                            'bg-navy-700' => ! $isImpersonating,
                                        ])
                                    >
                                        {{ $userInitials }}
                                    </button>

                                    <div
                                        id="profile-menu"
                                        x-show="profileMenuOpen"
                                        style="display: none"
                                        class="absolute right-0 z-30 mt-2 w-[280px] overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg"
                                    >
                                        <div class="border-b border-gray-100 px-4 py-3">
                                            <p class="truncate text-[13px] font-bold text-gray-900">{{ $userName }}</p>
                                            <p class="truncate text-xs text-gray-500">{{ $authenticatedUser->email }}</p>
                                            <p class="mt-1.5"><span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700">{{ $userRole }}</span></p>
                                        </div>

                                        @if ($isImpersonating)
                                            <div role="status" class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-amber-950">
                                                <p class="text-xs font-semibold">Viewing as {{ $userName }}</p>
                                                <form method="POST" action="{{ route('impersonation.destroy') }}" class="mt-1.5">
                                                    @csrf
                                                    <button type="submit" class="text-xs font-semibold text-amber-900 underline hover:text-amber-700">Return to my account</button>
                                                </form>
                                            </div>
                                        @endif

                                        <ul class="py-1.5 text-[13px] text-gray-700">
                                            <li>
                                                <a href="{{ route('profile.show') }}" class="flex items-center px-4 py-2 hover:bg-gray-50 focus:bg-gray-50 focus:outline-none">My Profile</a>
                                            </li>
                                            <li>
                                                <a href="{{ route('notifications.index') }}" class="flex items-center justify-between px-4 py-2 hover:bg-gray-50 focus:bg-gray-50 focus:outline-none">
                                                    <span>Notifications</span>
                                                    @if ($unreadNotificationCount > 0)
                                                        <span class="inline-flex min-w-5 justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
                                                            {{ $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount }}
                                                            <span class="sr-only">unread</span>
                                                        </span>
                                                    @endif
                                                </a>
                                            </li>
                                            <li class="border-t border-gray-100 pt-1.5 mt-1.5">
                                                <form method="POST" action="{{ route('logout') }}">
                                                    @csrf
                                                    <button type="submit" class="flex w-full items-center px-4 py-2 text-left hover:bg-gray-50 focus:bg-gray-50 focus:outline-none">Sign out</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            @endauth
                        </div>
                    </div>

                    @if ($isImpersonating)
                        <div role="status" class="flex flex-wrap items-center justify-between gap-3 border-t border-amber-200 bg-amber-50 px-6 py-2.5 text-sm text-amber-950">
                            <p>
                                View As is active — you are signed in as <span class="font-semibold">{{ $userName }}</span>
                                @if ($impersonator)
                                    (started by {{ $impersonator->name }}).
                                @else
                                    .
                                @endif
                            </p>
                            <form method="POST" action="{{ route('impersonation.destroy') }}">
                                @csrf
                                <button type="submit" class="rounded-md bg-amber-900 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-amber-800">
                                    Return to Admin
                                </button>
                            </form>
                        </div>
                    @endif
                </header>

                <main class="min-h-0 flex-1 p-6 sm:px-7 sm:pb-10">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
