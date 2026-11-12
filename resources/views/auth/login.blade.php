<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="theme-{{ $appearance['theme'] }}" data-profile="{{ $appearance['profile'] }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Sign in | {{ config('app.name', 'AquaOps') }}</title>
        <x-theme-head :appearance="$appearance" />
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen flex-col bg-background font-sans text-fg antialiased">
        <main class="flex flex-1 flex-col items-center justify-center px-5 py-12">
            <a href="{{ route('login') }}" class="mb-7 flex items-center gap-2.5" aria-label="AquaOps sign in">
                <x-brand-mark size="size-10" />
                <span>
                    <span class="block text-lg font-extrabold tracking-[0.2px] text-fg">AquaOps</span>
                    <span class="block text-[10px] font-semibold uppercase tracking-[0.8px] text-muted">Operations Console</span>
                </span>
            </a>

            <section aria-labelledby="login-heading" class="w-full max-w-[420px] rounded-lg border border-border bg-surface p-7 shadow-lg shadow-fg/5 sm:p-9">
                <div class="mb-7">
                    <h1 id="login-heading" class="text-2xl font-extrabold tracking-tight text-fg">Welcome back</h1>
                    <p class="mt-2 text-sm text-muted">Sign in to access your operations workspace.</p>
                </div>

                @if ($errors->any())
                    <div role="alert" class="mb-5 rounded-md border border-danger/35 bg-danger-soft px-3.5 py-3 text-sm text-danger-ink">
                        <p>{{ $errors->first() }}</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <label for="email" class="mb-1.5 block text-sm font-semibold text-fg-soft">Work email</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            required
                            autofocus
                            autocomplete="username"
                            inputmode="email"
                            placeholder="you@company.com"
                            aria-describedby="{{ $errors->has('email') ? 'email-error' : '' }}"
                            @class([
                                'w-full rounded-md border bg-surface px-3.5 py-2.5 text-sm text-fg outline-none transition placeholder:text-muted focus:border-accent focus:ring-2 focus:ring-focus/15',
                                'border-danger/35' => $errors->has('email'),
                                'border-border-strong' => ! $errors->has('email'),
                            ])
                        >
                        @error('email')
                            <p id="email-error" class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-semibold text-fg-soft">Password</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your password"
                            class="w-full rounded-md border border-border-strong bg-surface px-3.5 py-2.5 text-sm text-fg outline-none transition placeholder:text-muted focus:border-accent focus:ring-2 focus:ring-focus/15"
                        >
                    </div>

                    <label for="remember" class="flex items-center gap-2.5 text-sm text-fg-soft">
                        <input id="remember" name="remember" type="checkbox" value="1" class="size-4 rounded border-border-strong text-accent-ink focus:ring-focus">
                        Remember me
                    </label>

                    <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-md bg-accent px-4 py-2.5 text-sm font-semibold text-on-accent shadow-sm transition hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2 ring-offset-surface">
                        Sign in
                        <x-heroicon-o-arrow-right class="size-4" aria-hidden="true" />
                    </button>
                </form>
            </section>

            <p class="mt-6 text-center text-xs text-muted">Access is managed by your AquaOps administrator.</p>
        </main>

        <x-app-footer reduced />
    </body>
</html>
