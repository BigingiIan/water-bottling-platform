@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $snapshot = $request->context_snapshot;
    $isOverdue = $request->isOverdue();
    $initialsFor = fn (string $name): string => collect(explode(' ', $name))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('');
    $secondaryButton = 'inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50';
@endphp

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route('requests.index') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 hover:text-blue-900">
            <x-heroicon-o-arrow-left class="size-3.5" aria-hidden="true" />
            All requests
        </a>
    </div>

    <x-page-header heading-id="request-heading" :title="$request->subject" :subtitle="$request->reference.' · '.$request->type->label().' · sent '.$request->created_at->diffForHumans()">
        <x-slot:actions>
            <x-request-priority-badge :priority="$request->priority" />
            <x-request-status-badge :status="$request->status" />
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="flex min-w-0 flex-col gap-6">
            <section aria-labelledby="request-message-heading" class="rounded-lg border border-gray-200 bg-white">
                <div class="flex items-center gap-3 border-b border-gray-100 px-[18px] py-3.5">
                    <span aria-hidden="true" class="flex size-8 shrink-0 items-center justify-center rounded-full bg-navy-700 text-[11px] font-bold text-white">{{ $initialsFor($request->sender?->name ?? '?') }}</span>
                    <div class="min-w-0">
                        <h2 id="request-message-heading" class="text-[13px] font-semibold text-gray-900">{{ $request->sender?->name }} <span class="font-normal text-gray-500">· {{ $request->sender?->role?->name }}</span></h2>
                        <p class="text-[11.5px] text-gray-500">To {{ $request->toRole?->name }} · {{ $settingsDate->formatDateTime($request->created_at) }}</p>
                    </div>
                </div>
                <div class="whitespace-pre-line px-[18px] py-4 text-sm leading-6 text-gray-800">{{ $request->body }}</div>

                @if (is_array($snapshot))
                    <div class="border-t border-gray-100 bg-gray-50 px-[18px] py-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">
                                <x-heroicon-o-link class="size-4" aria-hidden="true" />
                                {{ $snapshot['label'] ?? 'Related record' }}
                            </h3>
                            @if ($liveUrl)
                                <a href="{{ $liveUrl }}" class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 hover:text-blue-900">
                                    Open record
                                    <x-heroicon-o-arrow-top-right-on-square class="size-3.5" aria-hidden="true" />
                                </a>
                            @elseif ($request->related_type)
                                <span class="text-[11.5px] text-gray-500">Snapshot only. Your role can't open this record.</span>
                            @endif
                        </div>
                        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                            @foreach ($snapshot['fields'] ?? [] as $field)
                                <div class="min-w-0">
                                    <dt class="text-[11px] font-semibold text-gray-500">{{ $field['label'] }}</dt>
                                    <dd class="break-words text-[13px] text-gray-800">{{ $field['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        @isset($snapshot['captured_at'])
                            <p class="mt-3 text-[11px] text-gray-400">Captured when the request was sent, {{ $settingsDate->formatDateTime(\Illuminate\Support\Carbon::parse($snapshot['captured_at'])) }}.</p>
                        @endisset
                    </div>
                @endif

                @if ($canReviewAccess)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-amber-100 bg-amber-50 px-[18px] py-3 text-[13px] text-amber-900">
                        <span>Approving means changing this role's access. Nothing is granted automatically.</span>
                        <a href="{{ route('staff.permissions') }}" class="inline-flex items-center gap-1 font-semibold text-amber-900 underline hover:text-amber-700">
                            Review in Roles &amp; Permissions
                        </a>
                    </div>
                @endif
            </section>

            @if ($request->resolution_note)
                <section aria-label="Resolution" @class([
                    'rounded-lg border px-[18px] py-3.5 text-sm',
                    'border-red-200 bg-red-50 text-red-900' => $request->status === \App\Services\Requests\RequestStatus::Declined,
                    'border-green-200 bg-green-50 text-green-900' => $request->status !== \App\Services\Requests\RequestStatus::Declined,
                ])>
                    <p class="font-semibold">{{ $request->status === \App\Services\Requests\RequestStatus::Declined ? 'Declined' : 'Resolution' }}</p>
                    <p class="mt-1 whitespace-pre-line">{{ $request->resolution_note }}</p>
                </section>
            @endif

            <section aria-labelledby="request-thread-heading" class="rounded-lg border border-gray-200 bg-white">
                <h2 id="request-thread-heading" class="border-b border-gray-100 px-[18px] py-3.5 text-sm font-bold text-gray-900">
                    Thread <span class="font-normal text-gray-500">· {{ \App\Support\Plural::count($request->comments->count(), 'comment') }}</span>
                </h2>
                @if ($request->comments->isEmpty())
                    <p class="px-[18px] py-5 text-sm text-gray-500">No comments yet.</p>
                @else
                    <ol class="divide-y divide-gray-100">
                        @foreach ($request->comments->sortBy('id') as $comment)
                            <li wire:key="request-comment-{{ $comment->id }}" class="flex gap-3 px-[18px] py-3.5">
                                <span aria-hidden="true" class="flex size-7 shrink-0 items-center justify-center rounded-full bg-gray-200 text-[10.5px] font-bold text-gray-700">{{ $initialsFor($comment->author?->name ?? '?') }}</span>
                                <div class="min-w-0">
                                    <p class="text-[13px]"><span class="font-semibold text-gray-900">{{ $comment->author?->name }}</span> <span class="text-gray-500">· {{ $comment->author?->role?->name }} · {{ $comment->created_at?->diffForHumans() }}</span></p>
                                    <p class="mt-0.5 whitespace-pre-line text-sm text-gray-800">{{ $comment->body }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
                @if ($canComment)
                    <form wire:submit="addComment" class="border-t border-gray-100 px-[18px] py-3.5">
                        <label for="request-comment" class="sr-only">Add a comment</label>
                        <textarea id="request-comment" wire:model="commentBody" rows="3" maxlength="5000" placeholder="Add a comment" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"></textarea>
                        @error('body')
                            <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                        <div class="mt-2 flex justify-end">
                            <button type="submit" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">Post comment</button>
                        </div>
                    </form>
                @endif
            </section>
        </div>

        <aside class="flex flex-col gap-4">
            <section aria-labelledby="request-details-heading" class="rounded-lg border border-gray-200 bg-white">
                <h2 id="request-details-heading" class="border-b border-gray-100 px-[18px] py-3.5 text-sm font-bold text-gray-900">Details</h2>
                <dl class="grid gap-3.5 px-[18px] py-4 text-[13px]">
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Department</dt><dd class="text-right font-medium text-gray-900">{{ $request->toRole?->name }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Assigned to</dt><dd class="text-right font-medium text-gray-900">{{ $request->assignee?->name ?? 'Nobody yet' }}</dd></div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500">Due</dt>
                        <dd @class(['text-right font-medium', 'text-[#b91c1c]' => $isOverdue, 'text-gray-900' => ! $isOverdue])>
                            {{ $request->due_date ? $settingsDate->formatDate($request->due_date) : 'No due date' }}{{ $isOverdue ? ' · overdue' : '' }}
                        </dd>
                    </div>
                    @if ($request->resolved_at)
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Closed</dt><dd class="text-right font-medium text-gray-900">{{ $settingsDate->formatDateTime($request->resolved_at) }}</dd></div>
                    @endif
                    @if ($request->reopened_at)
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Reopened</dt><dd class="text-right font-medium text-gray-900">{{ $settingsDate->formatDateTime($request->reopened_at) }}</dd></div>
                    @endif
                </dl>
            </section>

            @if ($transitions !== [] || $canCancel || $canReopen)
                <section aria-labelledby="request-actions-heading" class="rounded-lg border border-gray-200 bg-white">
                    <h2 id="request-actions-heading" class="border-b border-gray-100 px-[18px] py-3.5 text-sm font-bold text-gray-900">Actions</h2>
                    <div class="flex flex-col gap-2 px-[18px] py-4">
                        @error('status')
                            <p role="alert" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</p>
                        @enderror

                        @if ($pendingStatus)
                            <form wire:submit="changeStatus('{{ $pendingStatus }}')" class="flex flex-col gap-2">
                                <label for="resolution-note" class="text-xs font-semibold text-gray-700">
                                    {{ $pendingStatus === 'declined' ? 'Why is it declined?' : 'Note for the sender (optional)' }}
                                </label>
                                <textarea id="resolution-note" wire:model="resolutionNote" rows="3" maxlength="2000" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-800"></textarea>
                                @error('resolutionNote')
                                    <p role="alert" class="text-xs text-red-700">{{ $message }}</p>
                                @enderror
                                <div class="flex gap-2">
                                    <button type="button" wire:click="cancelPendingStatus" class="{{ $secondaryButton }}">Back</button>
                                    <button type="submit" @class([
                                        'flex-1 rounded-md px-3 py-1.5 text-xs font-semibold text-white',
                                        'bg-red-600 hover:bg-red-700' => $pendingStatus === 'declined',
                                        'bg-green-600 hover:bg-green-700' => $pendingStatus !== 'declined',
                                    ])>{{ \App\Services\Requests\RequestStatus::from($pendingStatus)->actionLabel() }}</button>
                                </div>
                            </form>
                        @else
                            @if ($canManage && $request->status->isActive() && $request->assigned_to_user_id !== auth()->id())
                                <button type="button" wire:click="assignToMe" class="{{ $secondaryButton }} justify-center">
                                    <x-heroicon-o-user class="size-4" aria-hidden="true" />
                                    Assign to me
                                </button>
                            @endif
                            @foreach ($transitions as $transition)
                                <button type="button" wire:click="changeStatus('{{ $transition->value }}')" @class([
                                    'inline-flex items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold',
                                    'bg-blue-600 text-white hover:bg-blue-700' => $loop->first && $transition !== \App\Services\Requests\RequestStatus::Declined,
                                    'border border-red-200 bg-white text-red-700 hover:bg-red-50' => $transition === \App\Services\Requests\RequestStatus::Declined,
                                    'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $loop->first && $transition !== \App\Services\Requests\RequestStatus::Declined,
                                ])>{{ $transition->actionLabel() }}</button>
                            @endforeach
                            @if ($canCancel)
                                <button type="button" wire:click="cancelRequest" wire:confirm="Cancel this request? The department will see it as cancelled." class="{{ $secondaryButton }} justify-center">Cancel request</button>
                            @endif
                            @if ($canReopen)
                                <button type="button" wire:click="reopenRequest" class="{{ $secondaryButton }} justify-center">Reopen (once)</button>
                            @endif
                        @endif
                    </div>
                </section>
            @endif
        </aside>
    </div>
</div>
