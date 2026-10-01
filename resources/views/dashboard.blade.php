<x-app-layout title="Dashboard" breadcrumb-section="Home" active-item="Dashboard">
    <section aria-labelledby="dashboard-heading" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[0.8px] text-blue-600">AquaOps</p>
        <h1 id="dashboard-heading" class="mt-2 text-2xl font-extrabold tracking-tight text-gray-900">
            Welcome back, {{ auth()->user()->name }}
        </h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-gray-500">
            Your operations workspace is ready. Use the navigation to manage production, inventory, reports, staff, and role access.
        </p>
    </section>
</x-app-layout>
