<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Set your password | {{ config('app.name', 'AquaOps') }}</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen flex-col bg-gray-50 font-sans text-gray-800 antialiased">
        <main class="flex flex-1 flex-col items-center justify-center px-5 py-12">
            <div class="mb-7 flex items-center gap-2.5" aria-label="AquaOps">
                <x-brand-mark size="size-10" />
                <span>
                    <span class="block text-lg font-extrabold tracking-[0.2px] text-gray-900">AquaOps</span>
                    <span class="block text-[10px] font-semibold uppercase tracking-[0.8px] text-gray-500">Operations Console</span>
                </span>
            </div>

            <section aria-labelledby="password-set-heading" class="w-full max-w-[420px] rounded-lg border border-gray-200 bg-white p-7 shadow-lg shadow-navy-950/5 sm:p-9">
                <div class="mb-7">
                    <h1 id="password-set-heading" class="text-2xl font-extrabold tracking-tight text-gray-900">Set your password</h1>
                    <p class="mt-2 text-sm text-gray-500">Choose a new password of at least 12 characters to activate your account.</p>
                </div>

                @if ($errors->any())
                    <div role="alert" class="mb-5 rounded-md border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-[#b91c1c]">
                        <p>{{ $errors->first() }}</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('password.set.update') }}" class="space-y-5">
                    @csrf

                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-semibold text-gray-700">New password</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autofocus
                            autocomplete="new-password"
                            placeholder="At least 12 characters"
                            class="w-full rounded-md border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                        >
                        @error('password')
                            <p class="mt-1.5 text-xs text-[#b91c1c]">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="mb-1.5 block text-sm font-semibold text-gray-700">Confirm new password</label>
                        <input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="Repeat your new password"
                            class="w-full rounded-md border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                        >
                    </div>

                    <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-md bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Set password and continue
                    </button>
                </form>

                <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
                    @csrf
                    <button type="submit" class="text-xs font-medium text-gray-500 transition-colors hover:text-gray-800">Sign out</button>
                </form>
            </section>
        </main>

        <x-app-footer reduced />
    </body>
</html>
