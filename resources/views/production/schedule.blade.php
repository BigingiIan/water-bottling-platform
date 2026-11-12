<x-app-layout title="Schedule" breadcrumb-section="Production" active-item="Schedule">
    <x-page-header help="production" class="mb-6" title="Production Schedule" :subtitle="app(\App\Support\SettingsDate::class)->formatInstantDate(now())">
        @if (app(\App\Support\ModuleAccess::class)->can(auth()->user(), 'production', true))
            <x-slot:actions>
                <button type="button" onclick="Livewire.dispatch('open-create-modal')" class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">
                    <x-heroicon-o-plus class="size-4" aria-hidden="true" />
                    Create Schedule
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-line-status-strip class="mb-6" />

    <livewire:production-schedule-table />

    <livewire:log-production-modal />

    <livewire:create-schedule-modal />
</x-app-layout>
