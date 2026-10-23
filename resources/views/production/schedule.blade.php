<x-app-layout title="Schedule" breadcrumb-section="Production" active-item="Schedule">
    <div class="mb-5 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-[21px] font-extrabold tracking-tight text-gray-900">Production Schedule</h1>
            <p class="mt-1 text-[13px] text-gray-500">{{ app(\App\Support\SettingsDate::class)->formatInstantDate(now()) }}</p>
        </div>
        @if (app(\App\Support\ModuleAccess::class)->can(auth()->user(), 'production', true))
            <div class="flex shrink-0 gap-2">
                <button type="button" onclick="Livewire.dispatch('open-create-modal')" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">
                    + Create Schedule
                </button>
            </div>
        @endif
    </div>

    <livewire:production-schedule-table />

    <livewire:log-production-modal />

    <livewire:create-schedule-modal />
</x-app-layout>
