<div>
    @if ($isOpen)
        @php
            $inputClasses = 'w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-[13px] text-fg focus:border-accent focus:outline-none focus:ring-2 focus:ring-focus/20';
            $labelClasses = 'mb-1 block text-xs font-semibold text-fg-soft';
        @endphp
        <x-modal-dialog id="send-request" :title="$sentReference ? 'Request sent' : 'Send a request'" close="close" max-width="max-w-[560px]">
            @if ($sentReference)
                <div class="flex flex-col items-center gap-2 py-4 text-center">
                    <x-heroicon-o-check-circle class="size-10 text-success-ink" aria-hidden="true" />
                    <p class="text-sm font-semibold text-fg">{{ $sentReference }} is on its way.</p>
                    <p class="text-xs text-muted">The department has been notified. You'll hear back when they reply or change its status.</p>
                </div>
                <x-slot:footer>
                    <button type="button" wire:click="close" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Close</button>
                    <a href="{{ $sentUrl }}" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">View request</a>
                </x-slot:footer>
            @else
                <form id="send-request-form" wire:submit="send" class="flex flex-col gap-3.5">
                    @if ($snapshot)
                        <div class="rounded-md border border-border bg-surface-muted px-3 py-2.5">
                            <p class="text-[11px] font-bold uppercase tracking-[0.4px] text-muted">The department will see</p>
                            <p class="mt-1 text-[13px] font-semibold text-fg">{{ $snapshot['label'] }}</p>
                            <p class="mt-0.5 text-xs text-fg-soft">
                                {{ collect($snapshot['fields'])->map(fn (array $field): string => $field['label'].': '.$field['value'])->implode(' · ') }}
                            </p>
                        </div>
                    @endif

                    <div class="grid gap-3.5 sm:grid-cols-2">
                        <div>
                            <label for="request-department" class="{{ $labelClasses }}">Send to</label>
                            <select id="request-department" wire:model="toRoleId" required class="{{ $inputClasses }}">
                                <option value="">Choose a department…</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                                @endforeach
                            </select>
                            @error('to_role_id')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="request-type" class="{{ $labelClasses }}">Type</label>
                            <select id="request-type" wire:model="type" class="{{ $inputClasses }}">
                                @foreach ($types as $requestType)
                                    <option value="{{ $requestType->value }}">{{ $requestType->label() }}</option>
                                @endforeach
                            </select>
                            @error('type')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="request-priority" class="{{ $labelClasses }}">Priority</label>
                            <select id="request-priority" wire:model="priority" class="{{ $inputClasses }}">
                                @foreach ($priorities as $requestPriority)
                                    <option value="{{ $requestPriority->value }}">{{ $requestPriority->label() }}</option>
                                @endforeach
                            </select>
                            @error('priority')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="request-due" class="{{ $labelClasses }}">Due by</label>
                            <input id="request-due" type="date" wire:model="dueDate" min="{{ today()->toDateString() }}" class="{{ $inputClasses }}">
                            @error('due_date')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <label for="request-subject" class="{{ $labelClasses }}">Subject</label>
                        <input id="request-subject" type="text" wire:model="subject" required maxlength="160" class="{{ $inputClasses }}">
                        @error('subject')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="request-body" class="{{ $labelClasses }}">What do you need?</label>
                        <textarea id="request-body" wire:model="body" required rows="4" maxlength="5000" class="{{ $inputClasses }}" placeholder="Say what you need and by when, so the team can act without a follow-up."></textarea>
                        @error('body')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                </form>
                <x-slot:footer>
                    <button type="button" wire:click="close" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Cancel</button>
                    <button type="submit" form="send-request-form" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">
                        <x-heroicon-o-paper-airplane class="size-4" aria-hidden="true" />
                        Send request
                    </button>
                </x-slot:footer>
            @endif
        </x-modal-dialog>
    @endif
</div>
