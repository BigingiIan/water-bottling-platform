{{-- Public landing page. Shows the organisation's name and logo only: no counts or business data. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="theme-{{ $appearance['theme'] }}" data-profile="{{ $appearance['profile'] }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="{{ $organisationName }} operations console: production, inventory, orders and demand forecasting.">
        <title>{{ $organisationName }} | Operations Console</title>
        <x-theme-head :appearance="$appearance" />
        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="flex min-h-screen flex-col bg-background font-sans text-fg antialiased">
        <header class="border-b border-border bg-surface">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-5 sm:px-8">
                <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-2.5">
                    <x-brand-logo size="size-9" />
                    <span class="min-w-0">
                        <span class="block truncate text-[15px] font-extrabold tracking-[0.2px] text-fg">{{ $organisationName }}</span>
                        <span class="block text-[10px] font-semibold uppercase tracking-[0.8px] text-muted">Operations Console</span>
                    </span>
                </a>
                <a href="{{ route('login') }}" class="shrink-0 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-sm font-semibold text-fg-soft hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                    Sign in
                </a>
            </div>
        </header>

        <main class="flex-1">
            <section aria-labelledby="landing-heading" class="bg-sidebar text-sidebar-strong">
                <div class="mx-auto flex max-w-6xl flex-col items-start gap-6 px-5 py-16 sm:px-8 sm:py-24">
                    <x-brand-logo size="size-14" />
                    <h1 id="landing-heading" class="max-w-3xl text-3xl font-extrabold tracking-tight sm:text-5xl">{{ $organisationName }}</h1>
                    <p class="max-w-2xl text-base text-sidebar-text sm:text-lg">
                        One place to plan bottling runs, track stock and leakage, fulfil orders and forecast tomorrow's demand.
                    </p>
                    <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-md bg-sidebar-accent px-5 py-3 text-sm font-semibold text-on-sidebar-accent shadow-sm hover:bg-sidebar-accent/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-sidebar-accent focus-visible:ring-offset-2 focus-visible:ring-offset-sidebar">
                        Sign in
                        <x-heroicon-o-arrow-right class="size-4" aria-hidden="true" />
                    </a>
                    <p class="text-xs text-sidebar-muted">Staff accounts only. Access is managed by your administrator.</p>
                </div>
            </section>

            <section aria-labelledby="features-heading" class="mx-auto max-w-6xl px-5 py-12 sm:px-8 sm:py-16">
                <h2 id="features-heading" class="sr-only">What the console covers</h2>
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        ['icon' => 'calendar-days', 'title' => 'Production', 'text' => 'Schedule runs on each line, log output and catch process loss early.'],
                        ['icon' => 'archive-box', 'title' => 'Inventory', 'text' => 'Live stock by product and bay, with counts, movements and leakage checks.'],
                        ['icon' => 'clipboard-document-list', 'title' => 'Orders', 'text' => 'Take orders, dispatch the oldest stock first, then invoice and collect.'],
                        ['icon' => 'presentation-chart-line', 'title' => 'Forecasting', 'text' => 'Next-day demand per product, from sales history and the business calendar.'],
                    ] as $feature)
                        <li class="rounded-lg border border-border bg-surface p-5">
                            <span class="flex size-10 items-center justify-center rounded-md bg-accent-soft text-accent-ink">
                                <x-dynamic-component :component="'heroicon-o-'.$feature['icon']" class="size-5" aria-hidden="true" />
                            </span>
                            <h3 class="mt-4 text-sm font-bold text-fg">{{ $feature['title'] }}</h3>
                            <p class="mt-1.5 text-sm text-fg-soft">{{ $feature['text'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        </main>

        <x-app-footer reduced />
    </body>
</html>
