<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Sign in | {{ config('app.name', 'AquaOps') }}</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen flex-col bg-gray-50 font-sans text-gray-800 antialiased">
        <main class="flex flex-1 flex-col items-center justify-center px-5 py-12">
            <a href="{{ route('login') }}" class="mb-7 flex items-center gap-2.5" aria-label="AquaOps sign in">
                <x-brand-mark size="size-10" />
                <span>
                    <span class="block text-lg font-extrabold tracking-[0.2px] text-gray-900">AquaOps</span>
                    <span class="block text-[10px] font-semibold uppercase tracking-[0.8px] text-gray-500">Operations Console</span>
                </span>
            </a>

            <section aria-labelledby="login-heading" class="w-full max-w-[420px] rounded-lg border border-gray-200 bg-white p-7 shadow-lg shadow-navy-950/5 sm:p-9">
                <div class="mb-7">
                    <h1 id="login-heading" class="text-2xl font-extrabold tracking-tight text-gray-900">Welcome back</h1>
                    <p class="mt-2 text-sm text-gray-500">Sign in to access your operations workspace.</p>
                </div>

                @if ($errors->any())
                    <div role="alert" class="mb-5 rounded-md border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-[#b91c1c]">
                        <p>{{ $errors->first() }}</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <label for="email" class="mb-1.5 block text-sm font-semibold text-gray-700">Work email</label>
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
                                'w-full rounded-md border bg-white px-3.5 py-2.5 text-sm text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15',
                                'border-red-300' => $errors->has('email'),
                                'border-gray-300' => ! $errors->has('email'),
                            ])
                        >
                        @error('email')
                            <p id="email-error" class="mt-1.5 text-xs text-[#b91c1c]">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-semibold text-gray-700">Password</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your password"
                            class="w-full rounded-md border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                        >
                    </div>

                    <label for="remember" class="flex items-center gap-2.5 text-sm text-gray-600">
                        <input id="remember" name="remember" type="checkbox" value="1" class="size-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Remember me
                    </label>

                    <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-md bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Sign in
                        <x-heroicon-o-arrow-right class="size-4" aria-hidden="true" />
                    </button>
                </form>
            </section>

            <p class="mt-6 text-center text-xs text-gray-400">Access is managed by your AquaOps administrator.</p>
        </main>

        <x-app-footer reduced />
    </body>
</html>
