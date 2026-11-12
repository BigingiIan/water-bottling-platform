<div>
    @if ($isOpen && $steps !== [])
        <div
            x-data="{
                steps: @js($steps),
                index: 0,
                highlighted: null,
                panelStyle: '',
                get step() { return this.steps[this.index]; },
                get isLast() { return this.index === this.steps.length - 1; },
                targetFor(step) {
                    if (! step.target) return null;
                    const element = document.querySelector(`[data-tour='${step.target}']`);
                    if (! element) return null;
                    const rect = element.getBoundingClientRect();
                    const isVisible = rect.width > 0 && rect.height > 0 && rect.right > 0 && rect.left < window.innerWidth;
                    return isVisible ? element : null;
                },
                place() {
                    this.highlighted?.removeAttribute('data-tour-active');
                    const target = this.targetFor(this.step);
                    this.highlighted = target;
                    const isPhone = window.innerWidth < 640;

                    if (! target || isPhone) {
                        target?.setAttribute('data-tour-active', '');
                        this.panelStyle = isPhone ? 'left: 12px; right: 12px; bottom: 12px;' : 'left: 50%; top: 50%; transform: translate(-50%, -50%);';
                        return;
                    }

                    target.setAttribute('data-tour-active', '');
                    target.scrollIntoView({ block: 'nearest' });
                    const rect = target.getBoundingClientRect();
                    const width = 340;
                    const left = Math.min(Math.max(12, rect.left), window.innerWidth - width - 12);
                    const below = rect.bottom + 12;
                    const top = below + 220 > window.innerHeight ? Math.max(12, rect.top - 232) : below;
                    // Targets in the sidebar sit to the right of it instead of over it.
                    this.panelStyle = rect.width < 320 && rect.left < 260 && rect.height > 300
                        ? `left: ${rect.right + 12}px; top: 96px; width: ${width}px;`
                        : `left: ${left}px; top: ${top}px; width: ${width}px;`;
                },
                go(offset) {
                    this.index = Math.min(Math.max(0, this.index + offset), this.steps.length - 1);
                    this.$nextTick(() => { this.place(); this.$refs.panel.focus(); });
                },
                close() {
                    this.highlighted?.removeAttribute('data-tour-active');
                    $wire.close();
                },
            }"
            x-init="$nextTick(() => { place(); $refs.panel.focus(); })"
            x-on:resize.window="place()"
            x-on:keydown.escape.window="close()"
            class="fixed inset-0 z-[60]"
        >
            <div class="absolute inset-0 transition-colors" x-bind:class="highlighted ? 'bg-transparent' : 'bg-overlay'" aria-hidden="true" x-on:click="close()"></div>

            <section
                x-ref="panel"
                tabindex="-1"
                role="dialog"
                aria-modal="true"
                aria-labelledby="welcome-tour-title"
                aria-describedby="welcome-tour-body"
                x-bind:style="panelStyle"
                x-on:keydown.tab="
                    const focusable = [...$refs.panel.querySelectorAll('button:not([disabled])')];
                    if ($event.shiftKey && document.activeElement === focusable[0]) { $event.preventDefault(); focusable[focusable.length - 1].focus(); }
                    else if (! $event.shiftKey && document.activeElement === focusable[focusable.length - 1]) { $event.preventDefault(); focusable[0].focus(); }
                "
                class="absolute w-auto max-w-[calc(100vw-24px)] rounded-lg border border-border bg-surface p-5 shadow-xl focus:outline-none sm:w-[340px]"
            >
                <p class="text-[11px] font-bold uppercase tracking-[0.4px] text-accent-ink">
                    {{ __('help.tour.label') }} ·
                    <span x-text="@js(__('help.tour.step_count', ['current' => '__current__', 'total' => '__total__'])).replace('__current__', index + 1).replace('__total__', steps.length)"></span>
                </p>
                <h2 id="welcome-tour-title" class="mt-1.5 text-base font-bold text-fg" x-text="step.title"></h2>
                <p id="welcome-tour-body" class="mt-1.5 text-sm leading-6 text-fg-soft" x-text="step.body"></p>

                <div class="mt-4 flex items-center gap-1" aria-hidden="true">
                    <template x-for="(dot, dotIndex) in steps" x-bind:key="dot.key">
                        <span class="h-1.5 rounded-full transition-all" x-bind:class="dotIndex === index ? 'w-5 bg-accent' : 'w-1.5 bg-border-strong'"></span>
                    </template>
                </div>

                <div class="mt-4 flex items-center justify-between gap-2">
                    <button type="button" x-on:click="close()" class="text-xs font-semibold text-muted hover:text-fg">{{ __('help.tour.skip') }}</button>
                    <div class="flex gap-2">
                        <button type="button" x-on:click="go(-1)" x-bind:disabled="index === 0" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-xs font-semibold text-fg-soft hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-40">{{ __('help.tour.back') }}</button>
                        <button type="button" x-show="! isLast" x-on:click="go(1)" class="rounded-md bg-accent px-3 py-1.5 text-xs font-semibold text-on-accent hover:bg-accent-hover">{{ __('help.tour.next') }}</button>
                        <button type="button" x-show="isLast" x-on:click="close()" class="rounded-md bg-accent px-3 py-1.5 text-xs font-semibold text-on-accent hover:bg-accent-hover">{{ __('help.tour.finish') }}</button>
                    </div>
                </div>
            </section>
        </div>
    @endif
</div>
