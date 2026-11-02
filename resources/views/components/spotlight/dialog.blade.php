{{-- The spotlight search (ADR-026, resources/js/spotlight.js): one dialog per page, opened with ⌘K / Ctrl+K, "/", the field in the top bar or the icon on small screens. --}}
@php
    $spotlightConfig = [
        'url' => route('search'),
        'recentUrl' => route('search.recent'),
        'switchUrl' => route('current-team.update'),
        'debounce' => 120,
        'texts' => [
            'none' => __('Inga träffar.'),
            'count' => __(':results träffar i :groups grupper.'),
            'failed' => __('Sökningen gick inte att göra just nu. Försök igen.'),
            'more' => __('Visa alla :count'),
        ],
    ];
@endphp

<div x-data="spotlight(@js($spotlightConfig))" x-on:keydown.escape.window="open && close()">
    <div x-show="open" x-cloak class="fixed inset-0 z-[60]" x-on:keydown="trap($event)">
        <div x-show="open" x-on:click="close()" aria-hidden="true"
             x-transition:enter="motion-safe:transition-opacity motion-safe:duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             class="absolute inset-0 bg-ink/40 sm:backdrop-blur-[2px]"></div>

        <div x-ref="panel" role="dialog" aria-modal="true" aria-labelledby="spotlight-title"
             x-show="open"
             x-transition:enter="motion-safe:transition motion-safe:duration-150 motion-safe:ease-out" x-transition:enter-start="opacity-0 sm:translate-y-1 sm:scale-[0.99]" x-transition:enter-end="opacity-100 sm:translate-y-0 sm:scale-100"
             class="absolute inset-0 flex flex-col bg-white sm:inset-x-4 sm:bottom-auto sm:top-[10vh] sm:mx-auto sm:max-h-[78vh] sm:max-w-2xl sm:rounded-2xl sm:shadow-2xl sm:ring-1 sm:ring-stone-900/10">
            <h2 id="spotlight-title" class="sr-only">{{ __('Sök i NONOX') }}</h2>

            {{-- The field --}}
            <div class="flex items-center gap-3 border-b border-stone-200 px-4 py-3 sm:px-5">
                <svg class="size-5 shrink-0 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input x-ref="input" x-model="query" x-on:input="typed()" x-on:keydown="key($event)"
                       type="text" role="combobox" aria-autocomplete="list" aria-haspopup="listbox"
                       aria-expanded="true" aria-controls="spotlight-listbox" :aria-activedescendant="activeUid ? optionId(activeUid) : null"
                       aria-describedby="spotlight-help"
                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" enterkeyhint="go" maxlength="200"
                       placeholder="{{ __('Sök eller gå till …') }}" aria-label="{{ __('Sök eller gå till') }}"
                       class="min-w-0 flex-1 border-0 bg-transparent p-0 text-base text-ink placeholder:text-stone-500 focus:ring-0 sm:text-lg" />
                <span x-show="loading" class="size-4 shrink-0 rounded-full border-2 border-stone-200 border-t-accent-500 motion-safe:animate-spin" aria-hidden="true"></span>
                <kbd class="hidden rounded-md border border-stone-200 px-1.5 py-0.5 font-sans text-[11px] font-medium text-stone-500 sm:inline">Esc</kbd>
                <button type="button" x-on:click="close()" class="rounded-lg px-2 py-1 text-sm font-medium text-accent-700 hover:bg-stone-100 sm:hidden">{{ __('Stäng') }}</button>
            </div>

            {{-- What was understood: removable filters --}}
            <div x-ref="chips" x-show="chips.length > 0" class="flex flex-wrap items-center gap-1.5 border-b border-stone-100 px-4 py-2 sm:px-5">
                <span class="text-xs text-stone-500">{{ __('Filter:') }}</span>
                <template x-for="(chip, index) in chips" :key="chip.kind + chip.token">
                    <button type="button" x-on:click="removeChip(chip)" x-on:keydown="chipKey($event, index)"
                            class="group inline-flex items-center gap-1 rounded-full bg-accent-50 py-0.5 pl-2.5 pr-1.5 text-xs font-semibold text-accent-800 ring-1 ring-accent-200 hover:bg-accent-100 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500"
                            :aria-label="@js(__('Ta bort filtret')) + ' ' + chip.label">
                        <span x-text="chip.label"></span>
                        <svg class="size-3.5 text-accent-600 group-hover:text-accent-800" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </template>
            </div>

            {{-- Results --}}
            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 py-2 sm:px-3">
                <div id="spotlight-listbox" role="listbox" aria-label="{{ __('Sökresultat') }}">
                    <template x-for="group in groups" :key="group.key">
                        <div role="group" :aria-labelledby="'spotlight-group-' + group.key" class="py-1">
                            <div class="flex items-baseline justify-between gap-3 px-3 pb-1 pt-2">
                                <p :id="'spotlight-group-' + group.key" class="text-[11px] font-semibold uppercase tracking-wider text-stone-500">
                                    <span x-text="group.label"></span>
                                    <span x-show="group.more" class="font-normal normal-case tracking-normal text-stone-400" x-text="'· ' + group.totalLabel"></span>
                                </p>
                            </div>

                            <template x-for="result in group.results" :key="result.uid">
                                <div role="option" :id="optionId(result.uid)" :aria-selected="activeUid === result.uid ? 'true' : 'false'"
                                     x-on:click="choose(result, $event.metaKey || $event.ctrlKey)" x-on:mousemove="activeUid !== result.uid && (activeUid = result.uid, navigated = true)"
                                     :class="activeUid === result.uid ? 'bg-accent-50 shadow-[inset_3px_0_0_rgb(var(--accent-500))]' : ''"
                                     class="flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                                          :class="activeUid === result.uid ? 'bg-white text-accent-700 ring-1 ring-accent-200' : 'bg-stone-100 text-stone-500'" aria-hidden="true">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" :d="icon(result.icon)" /></svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium" :class="result.more ? 'text-accent-700' : 'text-ink'"><template x-for="(part, i) in parts(result.title)" :key="i"><span :class="part.hit ? 'rounded-sm bg-amber-100 text-ink' : ''" x-text="part.text"></span></template></span>
                                        <span x-show="result.subtitle" class="block truncate text-xs text-stone-500"><template x-for="(part, i) in parts(result.subtitle)" :key="i"><span :class="part.hit ? 'rounded-sm bg-amber-100 text-stone-700' : ''" x-text="part.text"></span></template></span>
                                    </span>
                                    <span class="flex shrink-0 flex-col items-end gap-0.5">
                                        <span x-show="result.meta" class="text-sm tabular-nums text-stone-700" x-text="result.meta"></span>
                                        <span x-show="result.badge" class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-600" x-text="result.badge"></span>
                                    </span>
                                    <svg x-show="activeUid === result.uid" class="size-4 shrink-0 text-accent-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                {{-- Nothing found, or nothing yet --}}
                <div x-show="!loading && !failed && groups.length === 0 && query.trim() !== ''" class="px-4 py-10 text-center">
                    <p class="font-medium text-ink">{{ __('Inget hittades') }}</p>
                    <p class="mt-1 text-sm text-stone-500">{{ __('Prova ett nummer (A47, 1029), ett namn, ett belopp som 8 750 eller "> 10000 kr", ett datum som 14 okt, eller "obetald".') }}</p>
                </div>
                <div x-show="!loading && !failed && groups.length === 0 && query.trim() === ''" class="px-4 py-10 text-center">
                    <p class="font-medium text-ink">{{ __('Sök i hela bokföringen') }}</p>
                    <p class="mt-1 text-sm text-stone-500">{{ __('Verifikationer, fakturor, kunder, leverantörer, konton och sidor. Det du öppnar hamnar här.') }}</p>
                </div>
                <p x-show="failed" class="px-4 py-10 text-center text-sm text-accent-700">{{ __('Sökningen gick inte att göra just nu. Försök igen.') }}</p>
            </div>

            {{-- Keys --}}
            <div id="spotlight-help" class="hidden items-center gap-4 border-t border-stone-200 bg-stone-50/80 px-5 py-2 text-[11px] text-stone-500 sm:flex sm:rounded-b-2xl">
                <span><kbd class="font-sans font-semibold">↑↓</kbd> {{ __('välj') }}</span>
                <span><kbd class="font-sans font-semibold">↵</kbd> {{ __('öppna') }}</span>
                <span><kbd class="font-sans font-semibold" x-text="mac ? '⌘↵' : 'Ctrl ↵'"></kbd> {{ __('ny flik') }}</span>
                <span><kbd class="font-sans font-semibold">Tab</kbd> {{ __('nästa grupp') }}</span>
                <span class="ml-auto tabular-nums" x-show="ms !== null && query.trim() !== ''" x-text="ms + ' ms'"></span>
            </div>

            <p class="sr-only" role="status" aria-live="polite" aria-atomic="true" x-text="announcement"></p>
        </div>
    </div>
</div>
