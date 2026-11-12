@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $snapshot = $request->context_snapshot;
    $isOverdue = $request->isOverdue();
    $initialsFor = fn (string $name): string => collect(explode(' ', $name))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('');
    $secondaryButton = 'inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3 py-1.5 text-xs font-semibold text-fg-soft hover:bg-surface-muted';
@endphp

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route('requests.index') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-accent-ink hover:text-accent-ink">
            <x-heroicon-o-arrow-left class="size-3.5" aria-hidden="true" />
            All requests
        </a>
    </div>

    <x-page-header help="requests" heading-id="request-heading" :title="$request->subject" :subtitle="$request->reference.' · '.$request->type->label().' · sent '.$request->created_at->diffForHumans()">
        <x-slot:actions>
            <x-request-priority-badge :priority="$request->priority" />
            <x-request-status-badge :status="$request->status" />
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="flex min-w-0 flex-col gap-6">
            <section aria-labelledby="request-message-heading" class="rounded-lg border border-border bg-surface">
                <div class="flex items-center gap-3 border-b border-border px-[18px] py-3.5">
                    <span aria-hidden="true" class="flex size-8 shrink-0 items-center justify-center rounded-full bg-fg-soft text-[11px] font-bold text-surface">{{ $initialsFor($request->sender?->name ?? '?') }}</span>
                    <div class="min-w-0">
                        <h2 id="request-message-heading" class="text-[13px] font-semibold text-fg">{{ $request->sender?->name }} <span class="font-normal text-muted">· {{ $request->sender?->role?->name }}</span></h2>
                        <p class="text-[11.5px] text-muted">To {{ $request->toRole?->name }} · {{ $settingsDate->formatDateTime($request->created_at) }}</p>
                    </div>
                </div>
                <div class="whitespace-pre-line px-[18px] py-4 text-sm leading-6 text-fg">{{ $request->body }}</div>

                @if (is_array($snapshot))
                    <div class="border-t border-border bg-surface-muted px-[18px] py-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">
                                <x-heroicon-o-link class="size-4" aria-hidden="true" />
                                {{ $snapshot['label'] ?? 'Related record' }}
                            </h3>
                            @if ($liveUrl)
                                <a href="{{ $liveUrl }}" class="inline-flex items-center gap-1 text-xs font-semibold text-accent-ink hover:text-accent-ink">
                                    Open record
                                    <x-heroicon-o-arrow-top-right-on-square class="size-3.5" aria-hidden="true" />
                                </a>
                            @elseif ($request->related_type)
                                <span class="text-[11.5px] text-muted">Snapshot only. Your role can't open this record.</span>
                            @endif
                        </div>
                        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                            @foreach ($snapshot['fields'] ?? [] as $field)
                                <div class="min-w-0">
                                    <dt class="text-[11px] font-semibold text-muted">{{ $field['label'] }}</dt>
                                    <dd class="break-words text-[13px] text-fg">{{ $field['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        @isset($snapshot['captured_at'])
                            <p class="mt-3 text-[11px] text-muted">Captured when the request was sent, {{ $settingsDate->formatDateTime(\Illuminate\Support\Carbon::parse($snapshot['captured_at'])) }}.</p>
                        @endisset
                    </div>
                @endif

                @if ($canCreateWorkOrder)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-info/20 bg-info-soft px-[18px] py-3 text-[13px] text-info-ink">
                        <span>Turn this into a corrective work order for the equipment.</span>
                        <button type="button" wire:click="createWorkOrder" class="rounded-md bg-accent px-3 py-1.5 text-xs font-semibold text-on-accent hover:bg-accent-hover">{{ __('maintenance.request_button') }}</button>
                    </div>
                @endif
                @if ($canReviewAccess)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-warning/20 bg-warning-soft px-[18px] py-3 text-[13px] text-warning-ink">
                        <span>Approving means changing this role's access. Nothing is granted automatically.</span>
                        <a href="{{ route('staff.permissions') }}" class="inline-flex items-center gap-1 font-semibold text-warning-ink underline hover:text-warning-ink">
                            Review in Roles &amp; Permissions
                        </a>
                    </div>
                @endif
            </section>

            @if ($request->resolution_note)
                <section aria-label="Resolution" @class([
                    'rounded-lg border px-[18px] py-3.5 text-sm',
                    'border-danger/35 bg-danger-soft text-danger-ink' => $request->status === \App\Services\Requests\RequestStatus::Declined,
                    'border-success/35 bg-success-soft text-success-ink' => $request->status !== \App\Services\Requests\RequestStatus::Declined,
                ])>
                    <p class="font-semibold">{{ $request->status === \App\Services\Requests\RequestStatus::Declined ? 'Declined' : 'Resolution' }}</p>
                    <p class="mt-1 whitespace-pre-line">{{ $request->resolution_note }}</p>
                </section>
            @endif

            <section aria-labelledby="request-thread-heading" class="rounded-lg border border-border bg-surface">
                <h2 id="request-thread-heading" class="border-b border-border px-[18px] py-3.5 text-sm font-bold text-fg">
                    Thread <span class="font-normal text-muted">· {{ \App\Support\Plural::count($request->comments->count(), 'comment') }}</span>
                </h2>
                @if ($request->comments->isEmpty())
                    <p class="px-[18px] py-5 text-sm text-muted">No comments yet.</p>
                @else
                    <ol class="divide-y divide-border">
                        @foreach ($request->comments->sortBy('id') as $comment)
                            <li wire:key="request-comment-{{ $comment->id }}" class="flex gap-3 px-[18px] py-3.5">
                                <span aria-hidden="true" class="flex size-7 shrink-0 items-center justify-center rounded-full bg-border text-[10.5px] font-bold text-fg-soft">{{ $initialsFor($comment->author?->name ?? '?') }}</span>
                                <div class="min-w-0">
                                    <p class="text-[13px]"><span class="font-semibold text-fg">{{ $comment->author?->name }}</span> <span class="text-muted">· {{ $comment->author?->role?->name }} · {{ $comment->created_at?->diffForHumans() }}</span></p>
                                    <p class="mt-0.5 whitespace-pre-line text-sm text-fg">{{ $comment->body }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
                @if ($canComment)
                    <form wire:submit="addComment" class="border-t border-border px-[18px] py-3.5">
                        <label for="request-comment" class="sr-only">Add a comment</label>
                        <textarea id="request-comment" wire:model="commentBody" rows="3" maxlength="5000" placeholder="Add a comment" class="w-full rounded-md border border-border-strong px-3 py-2 text-sm text-fg focus:border-accent focus:outline-none focus:ring-2 focus:ring-focus/20"></textarea>
                        @error('body')
                            <p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>
                        @enderror
                        <div class="mt-2 flex justify-end">
                            <button type="submit" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">Post comment</button>
                        </div>
                    </form>
                @endif
            </section>
        </div>

        <aside class="flex flex-col gap-4">
            <section aria-labelledby="request-details-heading" class="rounded-lg border border-border bg-surface">
                <h2 id="request-details-heading" class="border-b border-border px-[18px] py-3.5 text-sm font-bold text-fg">Details</h2>
                <dl class="grid gap-3.5 px-[18px] py-4 text-[13px]">
                    <div class="flex justify-between gap-3"><dt class="text-muted">Department</dt><dd class="text-right font-medium text-fg">{{ $request->toRole?->name }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Assigned to</dt><dd class="text-right font-medium text-fg">{{ $request->assignee?->name ?? 'Nobody yet' }}</dd></div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">Due</dt>
                        <dd @class(['text-right font-medium', 'text-danger-ink' => $isOverdue, 'text-fg' => ! $isOverdue])>
                            {{ $request->due_date ? $settingsDate->formatDate($request->due_date) : 'No due date' }}{{ $isOverdue ? ' · overdue' : '' }}
                        </dd>
                    </div>
                    @if ($request->resolved_at)
                        <div class="flex justify-between gap-3"><dt class="text-muted">Closed</dt><dd class="text-right font-medium text-fg">{{ $settingsDate->formatDateTime($request->resolved_at) }}</dd></div>
                    @endif
                    @if ($request->reopened_at)
                        <div class="flex justify-between gap-3"><dt class="text-muted">Reopened</dt><dd class="text-right font-medium text-fg">{{ $settingsDate->formatDateTime($request->reopened_at) }}</dd></div>
                    @endif
                </dl>
            </section>

            @if ($transitions !== [] || $canCancel || $canReopen)
                <section aria-labelledby="request-actions-heading" class="rounded-lg border border-border bg-surface">
                    <h2 id="request-actions-heading" class="border-b border-border px-[18px] py-3.5 text-sm font-bold text-fg">Actions</h2>
                    <div class="flex flex-col gap-2 px-[18px] py-4">
                        @error('status')
                            <p role="alert" class="rounded-md border border-danger/35 bg-danger-soft px-3 py-2 text-xs text-danger-ink">{{ $message }}</p>
                        @enderror

                        @if ($pendingStatus)
                            <form wire:submit="changeStatus('{{ $pendingStatus }}')" class="flex flex-col gap-2">
                                <label for="resolution-note" class="text-xs font-semibold text-fg-soft">
                                    {{ $pendingStatus === 'declined' ? 'Why is it declined?' : 'Note for the sender (optional)' }}
                                </label>
                                <textarea id="resolution-note" wire:model="resolutionNote" rows="3" maxlength="2000" class="w-full rounded-md border border-border-strong px-3 py-2 text-sm text-fg"></textarea>
                                @error('resolutionNote')
                                    <p role="alert" class="text-xs text-danger-ink">{{ $message }}</p>
                                @enderror
                                <div class="flex gap-2">
                                    <button type="button" wire:click="cancelPendingStatus" class="{{ $secondaryButton }}">Back</button>
                                    <button type="submit" @class([
                                        'flex-1 rounded-md px-3 py-1.5 text-xs font-semibold',
                                        'bg-danger text-on-danger hover:bg-danger-hover' => $pendingStatus === 'declined',
                                        'bg-success text-on-success hover:bg-success-hover' => $pendingStatus !== 'declined',
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
                                    'bg-accent text-on-accent hover:bg-accent-hover' => $loop->first && $transition !== \App\Services\Requests\RequestStatus::Declined,
                                    'border border-danger/35 bg-surface text-danger-ink hover:bg-danger-soft' => $transition === \App\Services\Requests\RequestStatus::Declined,
                                    'border border-border-strong bg-surface text-fg-soft hover:bg-surface-muted' => ! $loop->first && $transition !== \App\Services\Requests\RequestStatus::Declined,
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
