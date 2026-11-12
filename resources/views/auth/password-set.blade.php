<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="theme-{{ $appearance['theme'] }}" data-profile="{{ $appearance['profile'] }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Set your password | {{ config('app.name', 'AquaOps') }}</title>
        <x-theme-head :appearance="$appearance" />
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen flex-col bg-background font-sans text-fg antialiased">
        <main class="flex flex-1 flex-col items-center justify-center px-5 py-12">
            <div class="mb-7 flex items-center gap-2.5" aria-label="AquaOps">
                <x-brand-mark size="size-10" />
                <span>
                    <span class="block text-lg font-extrabold tracking-[0.2px] text-fg">AquaOps</span>
                    <span class="block text-[10px] font-semibold uppercase tracking-[0.8px] text-muted">Operations Console</span>
                </span>
            </div>

            <section aria-labelledby="password-set-heading" class="w-full max-w-[420px] rounded-lg border border-border bg-surface p-7 shadow-lg shadow-fg/5 sm:p-9">
                <div class="mb-7">
                    <h1 id="password-set-heading" class="text-2xl font-extrabold tracking-tight text-fg">Set your password</h1>
                    <p class="mt-2 text-sm text-muted">Choose a new password of at least 12 characters to activate your account.</p>
                </div>

                @if ($errors->any())
                    <div role="alert" class="mb-5 rounded-md border border-danger/35 bg-danger-soft px-3.5 py-3 text-sm text-danger-ink">
                        <p>{{ $errors->first() }}</p>
                    </div>
                @endif

                @if ($isViewingAs ?? false)
                    <div role="alert" class="mb-5 rounded-md border border-danger/35 bg-danger-soft px-3.5 py-3 text-sm text-danger-ink">
                        <p>This account still has a temporary password. Passwords cannot be set while using View As.</p>
                    </div>

                    <form method="POST" action="{{ route('impersonation.destroy') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-md bg-accent px-4 py-2.5 text-sm font-semibold text-on-accent shadow-sm transition hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2 ring-offset-surface">
                            Return to Admin
                        </button>
                    </form>
                @else
                <form method="POST" action="{{ route('password.set.update') }}" class="space-y-5">
                    @csrf

                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-semibold text-fg-soft">New password</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autofocus
                            autocomplete="new-password"
                            placeholder="At least 12 characters"
                            class="w-full rounded-md border border-border-strong bg-surface px-3.5 py-2.5 text-sm text-fg outline-none transition placeholder:text-muted focus:border-accent focus:ring-2 focus:ring-focus/15"
                        >
                        @error('password')
                            <p class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="mb-1.5 block text-sm font-semibold text-fg-soft">Confirm new password</label>
                        <input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="Repeat your new password"
                            class="w-full rounded-md border border-border-strong bg-surface px-3.5 py-2.5 text-sm text-fg outline-none transition placeholder:text-muted focus:border-accent focus:ring-2 focus:ring-focus/15"
                        >
                    </div>

                    <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-md bg-accent px-4 py-2.5 text-sm font-semibold text-on-accent shadow-sm transition hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2 ring-offset-surface">
                        Set password and continue
                    </button>
                </form>
                @endif

                <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
                    @csrf
                    <button type="submit" class="text-xs font-medium text-muted transition-colors hover:text-fg">Sign out</button>
                </form>
            </section>
        </main>

        <x-app-footer reduced />
    </body>
</html>
