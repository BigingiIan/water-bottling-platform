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
            'slug' => null,
            'items' => [
                ['label' => 'Dashboard', 'module' => 'dashboard', 'alwaysVisible' => true, 'icon' => 'squares-2x2', 'href' => route('dashboard'), 'tour' => 'nav-dashboard'],
                ['label' => 'Requests', 'module' => 'requests', 'icon' => 'inbox-arrow-down', 'href' => route('requests.index'), 'badge' => $openRequestCount, 'tour' => 'nav-requests'],
            ],
        ],
        [
            'label' => 'Production',
            'slug' => 'production',
            'items' => [
                ['label' => 'Schedule', 'module' => 'production', 'icon' => 'calendar-days', 'href' => route('production.schedule')],
                ['label' => 'Production Runs', 'module' => 'production', 'icon' => 'play-circle', 'href' => route('production.runs')],
                ['label' => 'History', 'module' => 'production', 'icon' => 'clock', 'href' => route('production.history')],
            ],
        ],
        [
            'label' => 'Inventory',
            'slug' => 'inventory',
            'items' => [
                ['label' => 'Stock Overview', 'module' => 'inventory', 'icon' => 'archive-box', 'href' => route('inventory.overview')],
                ['label' => 'Orders', 'module' => 'orders', 'icon' => 'clipboard-document-list', 'href' => route('orders.index')],
            ],
        ],
        [
            'label' => 'Maintenance',
            'slug' => 'maintenance',
            'items' => [
                ['label' => 'Equipment', 'module' => 'maintenance', 'icon' => 'wrench-screwdriver', 'href' => route('maintenance.equipment.index')],
                ['label' => 'Work Orders', 'module' => 'maintenance', 'icon' => 'clipboard-document-check', 'href' => route('maintenance.work-orders.index')],
                ['label' => 'Maintenance Calendar', 'module' => 'maintenance', 'icon' => 'calendar-days', 'href' => route('maintenance.calendar')],
            ],
        ],
        [
            'label' => 'Finance',
            'slug' => 'finance',
            'items' => [
                ['label' => 'Invoices', 'module' => 'invoicing', 'icon' => 'document-text', 'href' => route('invoices.index')],
                ['label' => 'Revenue', 'module' => 'revenue', 'icon' => 'banknotes', 'href' => route('revenue.index')],
            ],
        ],
        [
            'label' => 'Analytics',
            'slug' => 'analytics',
            'items' => [
                ['label' => 'Forecasting', 'module' => 'forecasting', 'icon' => 'presentation-chart-line', 'href' => route('forecasting.index')],
                ['label' => 'Business Calendar', 'module' => 'calendar', 'icon' => 'calendar', 'href' => route('calendar.index')],
                ['label' => 'Reports', 'module' => 'reports', 'icon' => 'chart-bar', 'href' => '#'],
            ],
        ],
        [
            'label' => 'System',
            'slug' => 'system',
            'items' => [
                ['label' => 'Users & Roles', 'module' => 'users-roles', 'icon' => 'users', 'href' => route('staff.directory')],
                ['label' => 'Roles & Permissions', 'module' => 'users-roles', 'requiredAccess' => 'full', 'icon' => 'shield-check', 'href' => route('staff.permissions')],
                ['label' => 'Error Logs', 'module' => 'error-logs', 'icon' => 'exclamation-triangle', 'href' => route('errors.index')],
                ['label' => 'Settings', 'module' => 'settings', 'icon' => 'cog-6-tooth', 'href' => route('settings.index')],
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
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="theme-{{ $appearance['theme'] }}" data-profile="{{ $appearance['profile'] }}" @if ($reduceMotion) data-reduce-motion @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title }} | {{ config('app.name', 'AquaOps') }}</title>
        <x-theme-head :appearance="$appearance" />
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        {{-- A theme saved in My Profile or Settings repaints the open page with the tokens the server would render. --}}
        <script>
            window.addEventListener('appearance-changed', (event) => {
                const { theme, profile, stylesheet } = event.detail;
                const root = document.documentElement;

                document.getElementById('theme-tokens').textContent = stylesheet;
                document.querySelector('meta[name="color-scheme"]').content = theme === 'system' ? 'light dark' : theme;
                root.classList.remove('theme-light', 'theme-dark', 'theme-system');
                root.classList.add('theme-' + theme);
                root.dataset.profile = profile;
            });
        </script>
        <script>
            // Inline so it exists before Livewire starts Alpine; the initial state comes from the server.
            window.appShell = ({ collapsed, collapsedGroups, saveUrl }) => ({
                collapsed,
                collapsedGroups,
                drawerOpen: false,
                tooltip: { text: '', top: 0, visible: false },
                isDesktop() {
                    return window.matchMedia('(min-width: 1024px)').matches;
                },
                toggleSidebar() {
                    this.collapsed = ! this.collapsed;
                    this.hideTooltip();
                    this.save({ collapsed: this.collapsed });
                },
                isGroupCollapsed(group) {
                    return this.collapsedGroups.includes(group);
                },
                toggleGroup(group) {
                    const collapse = ! this.isGroupCollapsed(group);
                    this.collapsedGroups = collapse
                        ? [...this.collapsedGroups, group]
                        : this.collapsedGroups.filter((name) => name !== group);
                    this.save({ group, group_collapsed: collapse });
                },
                openDrawer() {
                    this.drawerOpen = true;
                },
                closeDrawer() {
                    if (! this.drawerOpen) {
                        return;
                    }

                    this.drawerOpen = false;
                    this.$nextTick(() => this.$refs.menuButton?.focus());
                },
                onShortcut(event) {
                    const target = event.target;

                    if (event.key !== '[' || event.ctrlKey || event.metaKey || event.altKey
                        || (target instanceof Element && target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])'))) {
                        return;
                    }

                    event.preventDefault();

                    if (this.isDesktop()) {
                        this.toggleSidebar();
                    } else if (this.drawerOpen) {
                        this.closeDrawer();
                    } else {
                        this.openDrawer();
                    }
                },
                showTooltip(event) {
                    if (! this.collapsed || ! this.isDesktop()) {
                        return;
                    }

                    const box = event.currentTarget.getBoundingClientRect();
                    this.tooltip = { text: event.currentTarget.dataset.label, top: box.top + box.height / 2, visible: true };
                },
                hideTooltip() {
                    this.tooltip.visible = false;
                },
                save(payload) {
                    fetch(saveUrl, {
                        method: 'PATCH',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(payload),
                    }).catch(() => {});
                },
            });
        </script>
    </head>
    <body class="min-h-screen bg-background text-fg antialiased">
        @if ($showSplash)
            {{-- Once per session, after sign-in. Rendered first so it paints before the page beneath it. --}}
            <div id="app-splash" role="status" class="app-splash fixed inset-0 z-[100] flex flex-col items-center justify-center gap-5 bg-surface print:hidden">
                <span class="app-splash-logo"><x-brand-logo size="size-14" :alt="app(\App\Support\Branding::class)->organisationName()" /></span>
                <p class="text-sm font-semibold text-fg-soft">Preparing your dashboard</p>
                <span class="app-splash-bar block h-1 w-40 overflow-hidden rounded-full bg-surface-strong" aria-hidden="true"><span class="block h-full w-1/3 rounded-full bg-accent"></span></span>
            </div>
            <script>
                (() => {
                    const splash = document.getElementById('app-splash');

                    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        splash.remove();

                        return;
                    }

                    let finished = false;
                    // The fade takes 150 ms, so starting it by 640 ms keeps the whole splash under 800 ms.
                    const finish = () => {
                        if (finished) {
                            return;
                        }

                        finished = true;
                        splash.classList.add('app-splash-leaving');
                        setTimeout(() => splash.remove(), 150);
                    };

                    window.addEventListener('load', finish, { once: true });
                    setTimeout(finish, 640);
                })();
            </script>
        @endif
        {{-- The saved rail and group state is rendered here, so the page loads in its final shape; Alpine only takes over the toggles. --}}
        <div
            class="flex min-h-screen"
            data-sidebar-state="{{ $sidebarCollapsed ? 'collapsed' : 'expanded' }}"
            x-data="appShell({ collapsed: @js($sidebarCollapsed), collapsedGroups: @js($collapsedNavGroups), saveUrl: @js(route('preferences.sidebar')) })"
            x-bind:data-sidebar-state="collapsed ? 'collapsed' : 'expanded'"
            x-on:keydown.escape.window="closeDrawer()"
            x-on:keydown.window="onShortcut($event)"
            x-on:resize.window="if (isDesktop()) drawerOpen = false"
        >
            <div x-show="drawerOpen" x-on:click="closeDrawer()" style="display: none" aria-hidden="true" class="fixed inset-0 z-40 bg-overlay lg:hidden"></div>
            <aside
                id="main-sidebar"
                aria-label="Sidebar"
                x-trap.noscroll="drawerOpen"
                x-bind:role="drawerOpen ? 'dialog' : null"
                x-bind:aria-modal="drawerOpen ? 'true' : null"
                x-bind:class="{
                    'translate-x-0': drawerOpen,
                    '-translate-x-full': ! drawerOpen,
                    'lg:w-16': collapsed,
                    'lg:w-[248px]': ! collapsed,
                }"
                @class([
                    'fixed inset-y-0 left-0 z-50 flex h-screen w-[264px] max-w-[85vw] shrink-0 -translate-x-full flex-col bg-sidebar py-5 text-sidebar-text transition-[transform,width] duration-200 lg:sticky lg:top-0 lg:z-auto lg:max-w-none lg:translate-x-0! print:hidden',
                    'lg:w-16' => $sidebarCollapsed,
                    'lg:w-[248px]' => ! $sidebarCollapsed,
                ])
            >
                <div
                    x-bind:class="{ 'lg:justify-center lg:px-0': collapsed }"
                    @class(['mb-4 flex items-center justify-between gap-2 border-b border-sidebar-strong/10 px-5 pb-[22px]', 'lg:justify-center lg:px-0' => $sidebarCollapsed])
                >
                    <a href="/" class="flex min-w-0 items-center gap-2.5" aria-label="AquaOps Operations Console, home">
                        <x-brand-mark />
                        <span x-bind:class="{ 'lg:hidden': collapsed }" @class(['min-w-0', 'lg:hidden' => $sidebarCollapsed])>
                            <span class="block text-[15px] font-extrabold tracking-[0.2px] text-sidebar-strong">AquaOps</span>
                            <span class="block text-[10.5px] font-medium uppercase tracking-[0.4px] text-sidebar-muted">Operations Console</span>
                        </span>
                    </a>
                    <button
                        type="button"
                        x-on:click="closeDrawer()"
                        class="flex size-9 shrink-0 items-center justify-center rounded-md text-sidebar-text hover:bg-sidebar-hover hover:text-sidebar-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-sidebar-accent lg:hidden"
                    >
                        <x-heroicon-o-x-mark class="size-5" aria-hidden="true" />
                        <span class="sr-only">Close navigation</span>
                    </button>
                </div>

                <nav aria-label="Main navigation" data-tour="sidebar" class="min-h-0 flex-1 overflow-y-auto overflow-x-hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    @foreach ($navigationSections as $navigationSection)
                        @if ($navigationSection['items'])
                            @php
                                $groupSlug = $navigationSection['slug'];
                                $groupCollapsed = $groupSlug !== null && in_array($groupSlug, $collapsedNavGroups, true);
                            @endphp
                            <div class="mb-1 px-3">
                                @if ($navigationSection['label'])
                                    <h2 x-bind:class="{ 'lg:hidden': collapsed }" @class(['px-3 pb-1.5 pt-3.5', 'lg:hidden' => $sidebarCollapsed])>
                                        <button
                                            type="button"
                                            aria-controls="nav-group-{{ $groupSlug }}"
                                            aria-expanded="{{ $groupCollapsed ? 'false' : 'true' }}"
                                            x-bind:aria-expanded="isGroupCollapsed(@js($groupSlug)) ? 'false' : 'true'"
                                            x-on:click="toggleGroup(@js($groupSlug))"
                                            class="group/heading flex w-full items-center justify-between gap-2 rounded text-left text-[10.5px] font-bold uppercase tracking-[0.8px] text-sidebar-muted hover:text-sidebar-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-sidebar-accent"
                                        >
                                            {{ $navigationSection['label'] }}
                                            <span
                                                x-bind:class="{ '-rotate-90': isGroupCollapsed(@js($groupSlug)) }"
                                                @class(['shrink-0 transition-transform', '-rotate-90' => $groupCollapsed])
                                                aria-hidden="true"
                                            >
                                                <x-heroicon-m-chevron-down class="size-3.5" />
                                            </span>
                                        </button>
                                    </h2>
                                    <div aria-hidden="true" x-bind:class="{ 'lg:block': collapsed }" @class(['mx-2 my-2 hidden border-t border-sidebar-strong/10', 'lg:block' => $sidebarCollapsed])></div>
                                @endif

                                <div id="nav-group-{{ $groupSlug ?? 'main' }}">
                                    @foreach ($navigationSection['items'] as $navigationItem)
                                        @php
                                            $itemHidden = $groupCollapsed && ! $navigationItem['active'];
                                            $badge = $navigationItem['badge'] ?? 0;
                                        @endphp
                                        {{-- A collapsed group still shows its active page; the icon rail always shows every item. --}}
                                        <div
                                            @if ($groupSlug !== null && ! $navigationItem['active'])
                                                x-bind:class="{ 'hidden': isGroupCollapsed(@js($groupSlug)), 'lg:block': collapsed }"
                                            @endif
                                            @class(['hidden' => $itemHidden, 'lg:block' => $itemHidden && $sidebarCollapsed])
                                        >
                                            <a
                                                href="{{ $navigationItem['href'] }}"
                                                data-label="{{ $navigationItem['label'] }}"
                                                @isset($navigationItem['tour']) data-tour="{{ $navigationItem['tour'] }}" @endisset
                                                @if ($navigationItem['active']) aria-current="page" @endif
                                                x-on:mouseenter="showTooltip($event)"
                                                x-on:mouseleave="hideTooltip()"
                                                x-on:focus="showTooltip($event)"
                                                x-on:blur="hideTooltip()"
                                                x-bind:class="{ 'lg:justify-center lg:px-0': collapsed }"
                                                @class([
                                                    'relative flex items-center gap-2.5 rounded-md px-3 py-2 text-[13.5px] font-medium text-sidebar-text transition-colors hover:bg-sidebar-hover hover:text-sidebar-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-sidebar-accent',
                                                    'bg-sidebar-active text-sidebar-strong before:absolute before:-left-3 before:inset-y-1.5 before:w-[3px] before:rounded-r before:bg-sidebar-accent' => $navigationItem['active'],
                                                    'lg:justify-center lg:px-0' => $sidebarCollapsed,
                                                ])
                                            >
                                                <span class="relative shrink-0">
                                                    <x-dynamic-component :component="'heroicon-o-'.$navigationItem['icon']" class="size-5" aria-hidden="true" />
                                                    @if ($badge > 0)
                                                        <span
                                                            data-rail-badge
                                                            x-bind:class="{ 'lg:inline-flex': collapsed }"
                                                            @class(['absolute -right-2 -top-1.5 hidden min-w-4 justify-center rounded-full bg-sidebar-accent px-1 py-px text-[9px] font-bold leading-none text-on-sidebar-accent ring-2 ring-sidebar', 'lg:inline-flex' => $sidebarCollapsed])
                                                        >
                                                            {{ $badge > 99 ? '99+' : $badge }}
                                                            <span class="sr-only">open</span>
                                                        </span>
                                                    @endif
                                                </span>
                                                <span x-bind:class="{ 'lg:sr-only': collapsed }" @class(['flex-1 truncate', 'lg:sr-only' => $sidebarCollapsed])>{{ $navigationItem['label'] }}</span>
                                                @if ($badge > 0)
                                                    <span x-bind:class="{ 'lg:hidden': collapsed }" @class(['inline-flex min-w-5 justify-center rounded-full bg-sidebar-accent px-1.5 py-0.5 text-[10px] font-bold leading-none text-on-sidebar-accent', 'lg:hidden' => $sidebarCollapsed])>
                                                        {{ $badge > 99 ? '99+' : $badge }}
                                                        <span class="sr-only">open</span>
                                                    </span>
                                                @endif
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </nav>

                <div class="hidden px-3 pt-2 lg:block">
                    <button
                        type="button"
                        aria-controls="main-sidebar"
                        aria-expanded="{{ $sidebarCollapsed ? 'false' : 'true' }}"
                        x-bind:aria-expanded="collapsed ? 'false' : 'true'"
                        aria-keyshortcuts="["
                        data-label="Expand sidebar"
                        x-on:click="toggleSidebar()"
                        x-on:mouseenter="showTooltip($event)"
                        x-on:mouseleave="hideTooltip()"
                        x-on:focus="showTooltip($event)"
                        x-on:blur="hideTooltip()"
                        x-bind:class="{ 'justify-center px-0': collapsed }"
                        @class(['flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-[12.5px] font-medium text-sidebar-text hover:bg-sidebar-hover hover:text-sidebar-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-sidebar-accent', 'justify-center px-0' => $sidebarCollapsed])
                    >
                        <span x-bind:class="{ 'rotate-180': collapsed }" @class(['shrink-0 transition-transform', 'rotate-180' => $sidebarCollapsed]) aria-hidden="true">
                            <x-heroicon-o-chevron-double-left class="size-5" />
                        </span>
                        <span x-bind:class="{ 'sr-only': collapsed }" @class(['flex-1 text-left', 'sr-only' => $sidebarCollapsed])>
                            <span x-text="collapsed ? 'Expand sidebar' : 'Collapse sidebar'">{{ $sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar' }}</span>
                        </span>
                        <kbd x-bind:class="{ 'hidden': collapsed }" @class(['rounded border border-sidebar-strong/15 px-1.5 font-sans text-[10.5px] text-sidebar-muted', 'hidden' => $sidebarCollapsed]) aria-hidden="true">[</kbd>
                    </button>
                </div>

                <div
                    x-bind:class="{ 'lg:justify-center lg:px-0': collapsed }"
                    @class(['mt-2 flex items-center gap-2.5 border-t border-sidebar-strong/10 px-5 pt-3.5', 'lg:justify-center lg:px-0' => $sidebarCollapsed])
                >
                    <span class="flex size-[30px] shrink-0 items-center justify-center rounded-full bg-sidebar-active text-[11px] font-bold text-sidebar-strong" title="{{ $userName }}">
                        {{ $userInitials }}
                    </span>
                    <span x-bind:class="{ 'lg:hidden': collapsed }" @class(['min-w-0', 'lg:hidden' => $sidebarCollapsed])>
                        <span class="block truncate text-[12.5px] font-semibold text-sidebar-strong">{{ $userName }}</span>
                        <span class="block truncate text-[10.5px] text-sidebar-muted">{{ $userRole }}</span>
                    </span>
                </div>
            </aside>

            {{-- One tooltip for the icon rail, outside the sidebar so its transform and scrolling can't clip it. --}}
            <div
                x-show="tooltip.visible"
                x-text="tooltip.text"
                x-bind:style="{ top: tooltip.top + 'px' }"
                style="display: none"
                aria-hidden="true"
                class="pointer-events-none fixed left-[72px] z-50 hidden -translate-y-1/2 whitespace-nowrap rounded-md bg-fg px-2.5 py-1.5 text-xs font-semibold text-surface shadow-lg lg:block"
            ></div>

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="sticky top-0 z-10 border-b border-border bg-surface print:hidden">
                    <div class="flex h-[60px] items-center justify-between gap-3 px-4 sm:px-6">
                        <button
                            type="button"
                            x-ref="menuButton"
                            x-on:click="openDrawer()"
                            aria-controls="main-sidebar"
                            x-bind:aria-expanded="drawerOpen ? 'true' : 'false'"
                            aria-expanded="false"
                            class="-ml-1 flex size-9 shrink-0 items-center justify-center rounded-md text-fg-soft hover:bg-surface-strong lg:hidden"
                        >
                            <x-heroicon-o-bars-3 class="size-6" aria-hidden="true" />
                            <span class="sr-only">Open navigation</span>
                        </button>
                        <nav aria-label="Breadcrumb" class="flex min-w-0 flex-1 items-center gap-1.5 truncate text-[13px] text-muted">
                            <span>{{ $breadcrumbSection }}</span>
                            <span aria-hidden="true">/</span>
                            <span aria-current="page" class="font-semibold text-fg">{{ $title }}</span>
                        </nav>

                        <div class="flex items-center gap-3.5">
                            @auth
                                <div data-tour="notifications">
                                    <livewire:notification-bell />
                                </div>
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
                                        data-tour="profile-menu"
                                        type="button"
                                        aria-label="Profile menu for {{ $userName }}"
                                        aria-haspopup="true"
                                        aria-controls="profile-menu"
                                        aria-expanded="false"
                                        x-bind:aria-expanded="profileMenuOpen ? 'true' : 'false'"
                                        x-on:click="profileMenuOpen = ! profileMenuOpen"
                                        @class([
                                            'flex size-[34px] items-center justify-center rounded-full text-[11px] font-bold ring-offset-2 ring-offset-surface transition hover:ring-2 hover:ring-focus/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus',
                                            'bg-warning text-on-warning' => $isImpersonating,
                                            'bg-sidebar-active text-sidebar-strong' => ! $isImpersonating,
                                        ])
                                    >
                                        {{ $userInitials }}
                                    </button>

                                    <div
                                        id="profile-menu"
                                        x-show="profileMenuOpen"
                                        style="display: none"
                                        class="absolute right-0 z-30 mt-2 w-[280px] overflow-hidden rounded-lg border border-border bg-surface shadow-lg"
                                    >
                                        <div class="border-b border-border px-4 py-3">
                                            <p class="truncate text-[13px] font-bold text-fg">{{ $userName }}</p>
                                            <p class="truncate text-xs text-muted">{{ $authenticatedUser->email }}</p>
                                            <p class="mt-1.5"><span class="inline-flex rounded-full bg-surface-strong px-2 py-0.5 text-xs font-semibold text-fg-soft">{{ $userRole }}</span></p>
                                        </div>

                                        @if ($isImpersonating)
                                            <div role="status" class="border-b border-warning/35 bg-warning-soft px-4 py-3 text-warning-ink">
                                                <p class="text-xs font-semibold">Viewing as {{ $userName }}</p>
                                                <form method="POST" action="{{ route('impersonation.destroy') }}" class="mt-1.5">
                                                    @csrf
                                                    <button type="submit" class="text-xs font-semibold text-warning-ink underline hover:no-underline">Return to my account</button>
                                                </form>
                                            </div>
                                        @endif

                                        @if ($canImpersonate)
                                            <form method="POST" action="{{ route('impersonation.store') }}" class="border-b border-border px-4 py-3">
                                                @csrf
                                                <label for="view-as-user" class="flex items-center gap-1.5 text-xs font-semibold text-fg-soft">
                                                    <x-heroicon-o-eye class="size-4 text-muted" aria-hidden="true" />
                                                    View as another user
                                                </label>
                                                <div class="mt-2 flex items-center gap-2">
                                                    <select id="view-as-user" name="user_id" required class="min-w-0 flex-1 rounded-md border border-border-strong bg-surface px-2 py-1.5 text-xs text-fg-soft">
                                                        <option value="" selected disabled>Choose a user…</option>
                                                        @foreach ($impersonationUsers as $impersonationUser)
                                                            <option value="{{ $impersonationUser->id }}">
                                                                {{ $impersonationUser->name }} — {{ $impersonationUser->role->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    <button type="submit" @disabled($impersonationUsers->isEmpty()) class="shrink-0 rounded-md bg-accent px-2.5 py-1.5 text-xs font-semibold text-on-accent hover:bg-accent-hover disabled:cursor-not-allowed disabled:bg-border-strong">
                                                        View As
                                                    </button>
                                                </div>
                                            </form>
                                        @endif

                                        <ul class="py-1.5 text-[13px] text-fg-soft">
                                            <li>
                                                <a href="{{ route('profile.show') }}" class="flex items-center gap-2.5 px-4 py-2 hover:bg-surface-muted focus:bg-surface-muted focus:outline-none">
                                                    <x-heroicon-o-user-circle class="size-5 text-muted" aria-hidden="true" />
                                                    My Profile
                                                </a>
                                            </li>
                                            <li>
                                                <a href="{{ route('notifications.index') }}" class="flex items-center gap-2.5 px-4 py-2 hover:bg-surface-muted focus:bg-surface-muted focus:outline-none">
                                                    <x-heroicon-o-bell class="size-5 text-muted" aria-hidden="true" />
                                                    <span class="flex-1">Notifications</span>
                                                    @if ($unreadNotificationCount > 0)
                                                        <span class="inline-flex min-w-5 justify-center rounded-full bg-danger px-1.5 py-0.5 text-[10px] font-bold leading-none text-on-danger">
                                                            {{ $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount }}
                                                            <span class="sr-only">unread</span>
                                                        </span>
                                                    @endif
                                                </a>
                                            </li>
                                            <li>
                                                <a href="{{ route('help') }}" class="flex items-center gap-2.5 px-4 py-2 hover:bg-surface-muted focus:bg-surface-muted focus:outline-none">
                                                    <x-heroicon-o-question-mark-circle class="size-5 text-muted" aria-hidden="true" />
                                                    {{ __('help.centre.title') }}
                                                </a>
                                            </li>
                                            <li class="border-t border-border pt-1.5 mt-1.5">
                                                <form method="POST" action="{{ route('logout') }}">
                                                    @csrf
                                                    <button type="submit" class="flex w-full items-center gap-2.5 px-4 py-2 text-left hover:bg-surface-muted focus:bg-surface-muted focus:outline-none">
                                                        <x-heroicon-o-arrow-right-start-on-rectangle class="size-5 text-muted" aria-hidden="true" />
                                                        Sign out
                                                    </button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            @endauth
                        </div>
                    </div>

                    @if ($isImpersonating)
                        <div role="status" class="flex flex-wrap items-center justify-between gap-3 border-t border-warning/35 bg-warning-soft px-6 py-2.5 text-sm text-warning-ink">
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
                                <button type="submit" class="rounded-md bg-warning px-3 py-1.5 text-xs font-semibold text-on-warning transition-colors hover:bg-warning-hover">
                                    Return to Admin
                                </button>
                            </form>
                        </div>
                    @endif
                </header>

                <main class="min-h-0 flex-1 p-4 sm:p-6 sm:px-7 sm:pb-10 print:p-0">
                    @if (session('status'))
                        <p role="status" class="mb-6 flex items-center gap-2 rounded-md border border-success/35 bg-success-soft px-4 py-2.5 text-sm font-medium text-success-ink">
                            <x-heroicon-o-check-circle class="size-5 shrink-0" aria-hidden="true" />
                            {{ session('status') }}
                        </p>
                    @endif
                    {{ $slot }}
                </main>

                <x-app-footer :show-forecast-status="in_array('forecasting', $accessibleModuleSlugs, true)" />

                @if (in_array('requests', $fullAccessModuleSlugs, true))
                    <livewire:send-request-modal />
                @endif

                @auth
                    <livewire:welcome-tour />
                @endauth
            </div>
        </div>
    </body>
</html>
