<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Recent activity" icon="clock" :href="route('settings.index', ['tab' => 'audit'])" link-label="Audit log">
        @if ($entries->isEmpty())
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-gray-500">No activity has been recorded yet.</p>
        @else
            <ul class="-mx-[18px] -my-4 divide-y divide-gray-100">
                @foreach ($entries as $entry)
                    <li wire:key="dashboard-activity-{{ $entry->id }}" class="flex items-start justify-between gap-3 px-[18px] py-2.5 text-[13px]">
                        <span class="min-w-0">
                            <span class="font-semibold text-gray-900">{{ $entry->user?->name ?? 'System' }}</span>
                            <span class="text-gray-600">· {{ \Illuminate\Support\Str::headline(str_replace('.', ' ', $entry->action)) }}</span>
                        </span>
                        <span class="shrink-0 whitespace-nowrap text-[11px] text-gray-400">{{ $entry->timestamp?->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-dashboard-card>
</div>
