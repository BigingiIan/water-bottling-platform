<x-app-layout title="Schedule" breadcrumb-section="Production" active-item="Schedule">
    <div class="mb-5 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-[21px] font-extrabold tracking-tight text-gray-900">Production Schedule</h1>
            <p class="mt-1 text-[13px] text-gray-500">{{ now()->format('d F Y') }}</p>
        </div>
        <div class="flex shrink-0 gap-2">
            <button type="button" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">
                📅 Change Date
            </button>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">
                + Create Schedule
            </button>
        </div>
    </div>

    <div class="mb-5 flex gap-0.5 border-b border-gray-200" role="tablist" aria-label="Production views">
        <button type="button" role="tab" aria-selected="true" class="mr-[22px] border-b-2 border-blue-600 px-1 py-2.5 text-[13.5px] font-semibold text-blue-600">Today's Schedule</button>
        <button type="button" role="tab" aria-selected="false" class="mr-[22px] border-b-2 border-transparent px-1 py-2.5 text-[13.5px] font-semibold text-gray-500 hover:text-gray-700">Active Runs</button>
        <button type="button" role="tab" aria-selected="false" class="mr-[22px] border-b-2 border-transparent px-1 py-2.5 text-[13.5px] font-semibold text-gray-500 hover:text-gray-700">History</button>
    </div>

    <livewire:production-schedule-table />

    <livewire:log-production-modal />
</x-app-layout>
