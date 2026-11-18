@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $thClasses = 'border-b border-border bg-surface-muted px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted';
    $inputClasses = 'w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-[13px] text-fg focus:border-accent focus:outline-none focus:ring-2 focus:ring-focus/20';
    $labelClasses = 'mb-1 block text-xs font-semibold text-fg-soft';
    $bandClasses = [
        'holiday' => 'border-danger bg-danger-soft text-danger-ink',
        'term' => 'border-success bg-success-soft text-success-ink',
        'promotion' => 'border-warning bg-warning-soft text-warning-ink',
    ];
    $statusClasses = ['planned' => 'bg-accent-soft text-accent-ink', 'active' => 'bg-success-soft text-success-ink', 'ended' => 'bg-surface-strong text-fg-soft'];
    $tabCreates = ['holidays' => 'holiday', 'terms' => 'school_term', 'promotions' => 'promotion'];
@endphp

<div class="flex flex-col gap-6">
    <x-page-header help="calendar" heading-id="calendar-heading" :title="__('calendar.title')" :subtitle="__('calendar.subtitle')">
        @if ($canManage)
            <x-slot:actions>
                <button type="button" wire:click="openCreate('{{ $tabCreates[$activeTab] ?? 'promotion' }}')" class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">
                    <x-heroicon-o-plus class="size-4" aria-hidden="true" />
                    {{ __('calendar.add.'.($tabCreates[$activeTab] ?? 'promotion')) }}
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <nav aria-label="{{ __('calendar.title') }}" class="flex flex-wrap gap-1 border-b border-border">
        @foreach (['month', 'holidays', 'terms', 'promotions'] as $tab)
            <button type="button" wire:click="$set('activeTab', '{{ $tab }}')" @class(['border-b-2 px-3 py-2.5 text-[13px] font-semibold', 'border-accent text-accent-ink' => $activeTab === $tab, 'border-transparent text-muted hover:text-fg' => $activeTab !== $tab]) @if ($activeTab === $tab) aria-current="page" @endif>{{ __('calendar.tabs.'.$tab) }}</button>
        @endforeach
    </nav>

    @if ($missingTermYears !== [] && in_array($activeTab, ['month', 'terms'], true))
        <p role="status" class="flex items-start gap-2 rounded-md border border-warning/35 bg-warning-soft px-4 py-2.5 text-sm text-warning-ink">
            <x-heroicon-o-exclamation-triangle class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            {{ __('calendar.missing_terms', ['years' => implode(', ', $missingTermYears)]) }}
        </p>
    @endif

    @if ($activeTab === 'month')
        <section aria-labelledby="calendar-month-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-[18px] py-3">
                <h2 id="calendar-month-heading" class="text-sm font-bold text-fg">{{ $monthStart->format('F Y') }}</h2>
                <div class="flex items-center gap-1.5">
                    <button type="button" wire:click="showMonth('{{ $monthStart->subMonth()->format('Y-m') }}')" aria-label="{{ __('calendar.previous_month') }}" class="flex size-8 items-center justify-center rounded-md border border-border-strong bg-surface text-fg-soft hover:bg-surface-muted"><x-heroicon-o-chevron-left class="size-4" aria-hidden="true" /></button>
                    <button type="button" wire:click="showMonth('')" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-xs font-semibold text-fg-soft hover:bg-surface-muted">{{ __('calendar.this_month') }}</button>
                    <button type="button" wire:click="showMonth('{{ $monthStart->addMonth()->format('Y-m') }}')" aria-label="{{ __('calendar.next_month') }}" class="flex size-8 items-center justify-center rounded-md border border-border-strong bg-surface text-fg-soft hover:bg-surface-muted"><x-heroicon-o-chevron-right class="size-4" aria-hidden="true" /></button>
                </div>
            </div>

            <ul aria-label="Legend" class="flex flex-wrap gap-x-4 gap-y-1.5 border-b border-border px-[18px] py-2.5 text-[11.5px] text-fg-soft">
                @foreach (['holiday', 'term', 'promotion'] as $kind)
                    <li class="flex items-center gap-1.5"><span aria-hidden="true" @class(['h-3 w-5 rounded-sm border-l-[3px]', $bandClasses[$kind]])></span>{{ __('calendar.legend.'.$kind) }}</li>
                @endforeach
            </ul>

            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[720px] table-fixed border-collapse">
                    <caption class="sr-only">{{ $monthStart->format('F Y') }}: public holidays, school terms and promotions per day</caption>
                    <thead>
                        <tr>
                            @foreach (__('calendar.weekdays') as $weekday)
                                <th scope="col" class="border-b border-border bg-surface-muted px-2 py-2 text-left text-[11px] font-bold uppercase tracking-[0.4px] text-muted">{{ $weekday }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($weeks as $week)
                            <tr>
                                @foreach ($week as $day)
                                    <td wire:key="calendar-day-{{ $day['date']->toDateString() }}" @class(['h-28 border-b border-r border-border p-1.5 align-top last:border-r-0', 'bg-surface-muted/70' => ! $day['inMonth']])>
                                        <span @class([
                                            'inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold',
                                            'bg-accent text-on-accent' => $day['isToday'],
                                            'text-fg' => ! $day['isToday'] && $day['inMonth'],
                                            'text-muted' => ! $day['isToday'] && ! $day['inMonth'],
                                        ])>{{ $day['date']->day }}<span class="sr-only"> {{ $day['date']->format('F') }}</span></span>
                                        <ul class="mt-1 flex flex-col gap-0.5">
                                            @foreach ($day['holidays'] as $holiday)
                                                <li @class(['truncate rounded-sm border-l-[3px] px-1.5 py-0.5 text-[10.5px] font-semibold', $bandClasses['holiday']]) title="{{ __('calendar.legend.holiday') }}: {{ $holiday }}"><span class="sr-only">{{ __('calendar.legend.holiday') }}: </span>{{ $holiday }}</li>
                                            @endforeach
                                            @if ($day['term'])
                                                <li @class(['truncate rounded-sm border-l-[3px] px-1.5 py-0.5 text-[10.5px] font-semibold', $bandClasses['term']]) title="{{ __('calendar.legend.term') }}: {{ $day['term'] }}"><span class="sr-only">{{ __('calendar.legend.term') }}: </span>{{ $day['term'] }}</li>
                                            @endif
                                            @foreach ($day['promotions'] as $promotion)
                                                <li @class(['truncate rounded-sm border-l-[3px] px-1.5 py-0.5 text-[10.5px] font-semibold', $bandClasses['promotion']]) title="{{ __('calendar.legend.promotion') }}: {{ $promotion }}"><span class="sr-only">{{ __('calendar.legend.promotion') }}: </span>{{ $promotion }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @else
        <section aria-labelledby="calendar-list-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
            <h2 id="calendar-list-heading" class="sr-only">{{ __('calendar.tabs.'.$activeTab) }}</h2>
            @php
                $rows = match ($activeTab) { 'holidays' => $holidays, 'terms' => $terms, default => $promotions };
            @endphp
            @if ($rows->isEmpty())
                <div class="flex flex-col items-center px-[18px] py-10 text-center">
                    <x-heroicon-o-calendar-days class="size-8 text-subtle" aria-hidden="true" />
                    <p class="mt-2 max-w-md text-sm text-fg-soft">{{ __('calendar.empty.'.$activeTab) }}</p>
                    @if ($canManage)
                        <button type="button" wire:click="openCreate('{{ $tabCreates[$activeTab] }}')" class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-accent px-3 py-1.5 text-xs font-semibold text-on-accent hover:bg-accent-hover">
                            <x-heroicon-o-plus class="size-3.5" aria-hidden="true" />
                            {{ __('calendar.add.'.$tabCreates[$activeTab]) }}
                        </button>
                    @endif
                </div>
            @else
                <div class="relative overflow-x-auto">
                    <table class="w-full min-w-[640px] border-separate border-spacing-0 text-left text-[13px]">
                        <thead>
                            <tr>
                                @if ($activeTab === 'holidays')
                                    <th scope="col" class="{{ $thClasses }}">{{ __('calendar.fields.name') }}</th>
                                    <th scope="col" class="{{ $thClasses }}">{{ __('calendar.fields.date') }}</th>
                                    <th scope="col" class="{{ $thClasses }}">{{ __('calendar.fields.recurring') }}</th>
                                @elseif ($activeTab === 'terms')
                                    <th scope="col" class="{{ $thClasses }}">{{ __('calendar.fields.year') }}</th>
                                    <th scope="col" class="{{ $thClasses }}">{{ __('calendar.fields.term') }}</th>
                                    <th scope="col" class="{{ $thClasses }}">{{ __('calendar.fields.start_date') }}</th>
                                    <th scope="col" class="{{ $thClasses }}">{{ __('calendar.fields.end_date') }}</th>
                                @else
                                    <th scope="col" class="{{ $thClasses }}">{{ __('calendar.fields.name') }}</th>
                                    <th scope="col" class="{{ $thClasses }}">{{ __('calendar.fields.product') }}</th>
                                    <th scope="col" class="{{ $thClasses }}">{{ __('calendar.fields.type') }}</th>
                                    <th scope="col" class="{{ $thClasses }}">{{ __('calendar.fields.start_date') }} – {{ __('calendar.fields.end_date') }}</th>
                                    <th scope="col" class="{{ $thClasses }}">Status</th>
                                @endif
                                <th scope="col" class="{{ $thClasses }}"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $entity = $tabCreates[$activeTab];
                                @endphp
                                <tr wire:key="calendar-{{ $entity }}-{{ $row->id }}" class="hover:bg-surface-muted">
                                    @if ($activeTab === 'holidays')
                                        <td class="border-b border-border px-[18px] py-3 font-semibold text-fg">{{ $row->name }}</td>
                                        <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-fg-soft">{{ $row->recurring ? $row->date->format('j F') : $settingsDate->formatDate($row->date) }}</td>
                                        <td class="border-b border-border px-[18px] py-3"><span class="whitespace-nowrap rounded px-2 py-0.5 text-[11px] font-bold {{ $row->recurring ? 'bg-danger-soft text-danger-ink' : 'bg-surface-strong text-fg-soft' }}">{{ $row->recurring ? __('calendar.every_year') : __('calendar.one_off') }}</span></td>
                                    @elseif ($activeTab === 'terms')
                                        <td class="border-b border-border px-[18px] py-3 font-semibold text-fg">{{ $row->year }}</td>
                                        <td class="border-b border-border px-[18px] py-3 text-fg-soft">{{ __('calendar.term_label', ['term' => $row->term]) }}</td>
                                        <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-fg-soft">{{ $settingsDate->formatDate($row->start_date) }}</td>
                                        <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-fg-soft">{{ $settingsDate->formatDate($row->end_date) }}</td>
                                    @else
                                        @php
                                            $status = $row->status();
                                        @endphp
                                        <td class="border-b border-border px-[18px] py-3">
                                            <span class="block font-semibold text-fg">{{ $row->name }}</span>
                                            @if ($row->discount_percent !== null)
                                                <span class="block text-[11.5px] text-muted">{{ rtrim(rtrim((string) $row->discount_percent, '0'), '.') }}% off</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-fg-soft">{{ $row->product?->unit_type ?? __('calendar.all_products') }}</td>
                                        <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-fg-soft">{{ __('calendar.promotion_types.'.$row->type) }}</td>
                                        <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-fg-soft">{{ $settingsDate->formatDate($row->start_date) }} – {{ $settingsDate->formatDate($row->end_date) }}</td>
                                        <td class="border-b border-border px-[18px] py-3"><span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-bold {{ $statusClasses[$status] }}">{{ __('calendar.promotion_status.'.$status) }}</span></td>
                                    @endif
                                    <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-right">
                                        @if ($canManage)
                                            <button type="button" wire:click="openEdit('{{ $entity }}', {{ $row->id }})" class="text-xs font-semibold text-accent-ink hover:text-accent-ink">{{ __('calendar.actions.edit') }}</button>
                                            <button type="button" wire:click="confirmDelete('{{ $entity }}', {{ $row->id }})" class="ml-3 text-xs font-semibold text-danger-ink hover:text-danger-ink">{{ __('calendar.actions.delete') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @if ($editingEntity)
        <x-modal-dialog id="calendar-form" :title="__(($editingId ? 'calendar.edit.' : 'calendar.add.').$editingEntity)" close="closeForm" max-width="max-w-[520px]">
            <form id="calendar-entry-form" wire:submit="save" class="flex flex-col gap-3.5">
                @if ($editingEntity === 'holiday')
                    <div>
                        <label for="calendar-name" class="{{ $labelClasses }}">{{ __('calendar.fields.name') }}</label>
                        <input id="calendar-name" type="text" wire:model="form.name" maxlength="120" class="{{ $inputClasses }}">
                        @error('form.name')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="calendar-date" class="{{ $labelClasses }}">{{ __('calendar.fields.date') }}</label>
                        <input id="calendar-date" type="date" wire:model="form.date" class="{{ $inputClasses }}">
                        @error('form.date')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-start gap-2 text-[13px] text-fg-soft">
                        <input type="checkbox" wire:model="form.recurring" class="mt-0.5 size-4 rounded border-border-strong text-accent-ink">
                        <span><span class="font-semibold">{{ __('calendar.fields.recurring') }}</span><span class="block text-xs text-muted">{{ __('calendar.fields.recurring_hint') }}</span></span>
                    </label>
                @elseif ($editingEntity === 'school_term')
                    <div class="grid grid-cols-2 gap-3.5">
                        <div>
                            <label for="calendar-year" class="{{ $labelClasses }}">{{ __('calendar.fields.year') }}</label>
                            <input id="calendar-year" type="number" min="2000" max="2100" wire:model="form.year" class="{{ $inputClasses }}">
                            @error('form.year')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="calendar-term" class="{{ $labelClasses }}">{{ __('calendar.fields.term') }}</label>
                            <select id="calendar-term" wire:model="form.term" class="{{ $inputClasses }}">
                                @foreach ([1, 2, 3] as $termNumber)
                                    <option value="{{ $termNumber }}">{{ __('calendar.term_label', ['term' => $termNumber]) }}</option>
                                @endforeach
                            </select>
                            @error('form.term')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="calendar-term-start" class="{{ $labelClasses }}">{{ __('calendar.fields.start_date') }}</label>
                            <input id="calendar-term-start" type="date" wire:model="form.start_date" class="{{ $inputClasses }}">
                            @error('form.start_date')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="calendar-term-end" class="{{ $labelClasses }}">{{ __('calendar.fields.end_date') }}</label>
                            <input id="calendar-term-end" type="date" wire:model="form.end_date" class="{{ $inputClasses }}">
                            @error('form.end_date')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                    </div>
                @else
                    <p class="flex items-start gap-2 rounded-md border border-info/20 bg-info-soft px-3 py-2 text-xs text-info-ink">
                        <x-heroicon-o-information-circle class="mt-px size-4 shrink-0" aria-hidden="true" />
                        {{ __('calendar.promotion_note') }}
                    </p>
                    <div>
                        <label for="calendar-promotion-name" class="{{ $labelClasses }}">{{ __('calendar.fields.name') }}</label>
                        <input id="calendar-promotion-name" type="text" wire:model="form.name" maxlength="160" class="{{ $inputClasses }}">
                        @error('form.name')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3.5">
                        <div>
                            <label for="calendar-promotion-product" class="{{ $labelClasses }}">{{ __('calendar.fields.product') }}</label>
                            <select id="calendar-promotion-product" wire:model="form.product_id" class="{{ $inputClasses }}">
                                <option value="">{{ __('calendar.fields.all_products') }}</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->unit_type }} · {{ $product->name }}</option>
                                @endforeach
                            </select>
                            @error('form.product_id')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="calendar-promotion-type" class="{{ $labelClasses }}">{{ __('calendar.fields.type') }}</label>
                            <select id="calendar-promotion-type" wire:model="form.type" class="{{ $inputClasses }}">
                                @foreach (\App\Models\Promotion::TYPES as $type)
                                    <option value="{{ $type }}">{{ __('calendar.promotion_types.'.$type) }}</option>
                                @endforeach
                            </select>
                            @error('form.type')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="calendar-promotion-start" class="{{ $labelClasses }}">{{ __('calendar.fields.start_date') }}</label>
                            <input id="calendar-promotion-start" type="date" wire:model="form.start_date" class="{{ $inputClasses }}">
                            @error('form.start_date')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="calendar-promotion-end" class="{{ $labelClasses }}">{{ __('calendar.fields.end_date') }}</label>
                            <input id="calendar-promotion-end" type="date" wire:model="form.end_date" class="{{ $inputClasses }}">
                            @error('form.end_date')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="calendar-promotion-discount" class="{{ $labelClasses }}">{{ __('calendar.fields.discount_percent') }}</label>
                            <input id="calendar-promotion-discount" type="number" min="0" max="100" step="0.5" wire:model="form.discount_percent" class="{{ $inputClasses }}">
                            @error('form.discount_percent')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div>
                        <label for="calendar-promotion-notes" class="{{ $labelClasses }}">{{ __('calendar.fields.notes') }}</label>
                        <textarea id="calendar-promotion-notes" rows="2" wire:model="form.notes" maxlength="2000" class="{{ $inputClasses }}"></textarea>
                        @error('form.notes')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                @endif

                @if ($needsReason)
                    <div class="rounded-md border border-warning/35 bg-warning-soft p-3">
                        <label for="calendar-reason" class="{{ $labelClasses }}">{{ __('calendar.fields.reason') }}</label>
                        <textarea id="calendar-reason" rows="2" wire:model="reason" maxlength="500" class="{{ $inputClasses }}"></textarea>
                        <p class="mt-1 text-[11px] text-warning-ink">{{ __('calendar.fields.reason_hint') }}</p>
                    </div>
                @endif
                @error('reason')<p role="alert" class="text-xs text-danger-ink">{{ $message }}</p>@enderror
            </form>
            <x-slot:footer>
                <button type="button" wire:click="closeForm" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">{{ __('calendar.actions.cancel') }}</button>
                <button type="submit" form="calendar-entry-form" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">{{ __('calendar.actions.save') }}</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif

    @if ($deletingEntity)
        <x-modal-dialog id="calendar-delete" :title="__('calendar.delete.title')" close="closeForm">
            <p class="text-sm text-fg-soft">{{ __('calendar.delete.body') }}</p>
            @if ($needsReason)
                <div class="rounded-md border border-warning/35 bg-warning-soft p-3">
                    <label for="calendar-delete-reason" class="{{ $labelClasses }}">{{ __('calendar.fields.reason') }}</label>
                    <textarea id="calendar-delete-reason" rows="2" wire:model="reason" maxlength="500" class="{{ $inputClasses }}"></textarea>
                    <p class="mt-1 text-[11px] text-warning-ink">{{ __('calendar.fields.reason_hint') }}</p>
                </div>
            @endif
            @error('reason')<p role="alert" class="text-xs text-danger-ink">{{ $message }}</p>@enderror
            <x-slot:footer>
                <button type="button" wire:click="closeForm" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">{{ __('calendar.actions.cancel') }}</button>
                <button type="button" wire:click="delete" class="rounded-md bg-danger px-3.5 py-2 text-[13px] font-semibold text-on-danger hover:bg-danger-hover">{{ __('calendar.delete.confirm') }}</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif
</div>
