@php
    $today = Carbon\CarbonImmutable::today();
    $urgentUntil = $today->addDays(App\Domain\Overview\TodoItem::URGENT_DAYS);
@endphp

<div class="space-y-6">
    <x-ui.page-heading
        :title="__('Hej :name!', ['name' => Str::before($user->name, ' ')])"
        :lead="__('Här är översikten för :company, :month.', ['company' => $company->name, 'month' => $month])">
        <x-slot:action>
            {{-- The top bar's search field (built separately) goes before this group. --}}
            <div class="flex items-center gap-2">
                <span class="text-xs tabular-nums text-gray-500" role="status" aria-live="polite">
                    <span wire:loading.remove wire:target="reload">{{ __('Uppdaterad :time', ['time' => $updatedAt]) }}</span>
                    <span wire:loading wire:target="reload">{{ __('Uppdaterar …') }}</span>
                </span>
                <button type="button" wire:click="reload" wire:loading.attr="disabled" wire:target="reload"
                        class="flex size-10 items-center justify-center rounded-full bg-white text-gray-700 shadow-xs ring-1 ring-stone-200 transition hover:text-ink hover:ring-stone-300 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 disabled:opacity-60"
                        title="{{ __('Ladda om översikten') }}">
                    <span class="sr-only">{{ __('Ladda om översikten') }}</span>
                    <svg class="size-5" wire:loading.class="animate-spin" wire:target="reload" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 12a8 8 0 1 1-2.34-5.66" /><path d="M20 4v5h-5" />
                    </svg>
                </button>
            </div>
        </x-slot:action>
    </x-ui.page-heading>

    @if($canSeeStatistics)
        <div class="flex gap-6 border-b border-gray-200 text-sm font-medium" role="tablist" aria-label="{{ __('Översikt') }}">
            @foreach(['oversikt' => __('Översikt'), 'statistik' => __('Statistik och prognos')] as $key => $label)
                <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}"
                        x-on:click="show('{{ $key }}')"
                        x-bind:aria-selected="tab === '{{ $key }}' ? 'true' : 'false'"
                        x-bind:tabindex="tab === '{{ $key }}' ? 0 : -1"
                        x-on:keydown.right.prevent="show('{{ $key === 'oversikt' ? 'statistik' : 'oversikt' }}'); $nextTick(() => document.getElementById('tab-{{ $key === 'oversikt' ? 'statistik' : 'oversikt' }}').focus())"
                        x-on:keydown.left.prevent="show('{{ $key === 'oversikt' ? 'statistik' : 'oversikt' }}'); $nextTick(() => document.getElementById('tab-{{ $key === 'oversikt' ? 'statistik' : 'oversikt' }}').focus())"
                        class="-mb-px border-b-2 pb-3 focus-visible:text-ink"
                        x-bind:class="tab === '{{ $key }}' ? 'border-accent-600 text-accent-700' : 'border-transparent text-gray-600 hover:text-gray-900'">{{ $label }}</button>
            @endforeach
        </div>
    @endif

    <div id="panel-oversikt" role="tabpanel" aria-labelledby="tab-oversikt" x-show="tab === 'oversikt'" class="space-y-6">
        @if($kpis !== [])
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Nyckeltal') }}">
                @foreach($kpis as $kpi)
                    @php($dark = $kpi['tone'] === 'dark')
                    @php($warn = $kpi['tone'] === 'warn')
                    <a href="{{ $kpi['link'] }}" @class([
                        'block rounded-3xl px-5 py-4 transition focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500',
                        'bg-ink text-white shadow-xs hover:bg-black' => $dark,
                        'bg-white shadow-xs ring-1 ring-stone-200 hover:ring-stone-300' => ! $dark && ! $warn,
                        'bg-accent-50 ring-1 ring-accent-200 hover:ring-accent-300' => $warn,
                    ]) data-kpi="{{ $kpi['key'] }}">
                        <span @class(['block text-sm', 'text-stone-300' => $dark, 'text-gray-600' => ! $dark])>{{ $kpi['label'] }}</span>
                        <x-ui.amount :money="App\Support\Money\Money::ofOre($kpi['value'])" :on-dark="$dark" @class(['mt-1 block text-2xl font-bold tracking-tight', 'text-white' => $dark, 'text-ink' => ! $dark]) />
                        @if($kpi['hint'])
                            <span @class([
                                'mt-2 inline-flex rounded-full text-xs',
                                'bg-white/10 px-2.5 py-1 text-stone-200' => $dark,
                                'bg-accent-100 px-2.5 py-1 font-semibold text-accent-800' => $warn,
                                'text-gray-600' => ! $dark && ! $warn,
                            ])>{{ $kpi['hint'] }}</span>
                        @endif
                    </a>
                @endforeach
            </section>
        @endif

        <div class="grid gap-6 xl:grid-cols-2">
            <x-ui.card :title="__('Att göra')" class="min-w-0">
                <x-slot:action>
                    @if($todos !== [])
                        <x-ui.badge :tone="collect($todos)->contains(fn ($todo) => $todo['due'] !== null && $todo['due'] <= $urgentUntil->toDateString()) ? 'accent' : 'neutral'">
                            {{ __(count($todos) === 1 ? '1 sak' : ':count saker', ['count' => count($todos)]) }}
                        </x-ui.badge>
                    @endif
                </x-slot:action>

                @if($todos === [])
                    <x-ui.empty-state :title="__('Inget som väntar')">
                        {{ __('Allt du kan göra något åt är gjort. Nya saker dyker upp här när de behöver dig.') }}
                    </x-ui.empty-state>
                @else
                    <ul class="-my-3 divide-y divide-gray-100" data-todos>
                        @foreach($todos as $todo)
                            @php($due = $todo['due'] === null ? null : Carbon\CarbonImmutable::parse($todo['due']))
                            @php($urgent = $due !== null && $due->lessThanOrEqualTo($urgentUntil))
                            <li class="flex items-center gap-4 py-3" data-todo="{{ $todo['key'] }}">
                                <span @class([
                                    'flex size-10 flex-none items-center justify-center rounded-2xl',
                                    'bg-accent-100 text-accent-700' => $urgent,
                                    'bg-stone-100 text-gray-700' => ! $urgent,
                                ])>
                                    <x-overview.icon :name="$todo['icon']" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold text-ink">{{ $todo['title'] }}</p>
                                    @if($todo['subtitle'] || $due)
                                        <p class="mt-0.5 text-sm text-gray-600">
                                            {{ $todo['subtitle'] }}
                                            @if($due !== null && $due->lessThan($today))
                                                <span class="font-semibold text-accent-700">{{ __('Förföll :date.', ['date' => $due->translatedFormat('j F')]) }}</span>
                                            @endif
                                        </p>
                                    @endif
                                </div>
                                <a href="{{ $todo['url'] }}" class="flex-none whitespace-nowrap rounded-sm text-sm font-semibold text-accent-600 hover:text-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">
                                    <span class="hidden sm:inline">{{ __('Gör det nu') }} →</span>
                                    <span class="sm:hidden" aria-hidden="true">→</span>
                                    <span class="sr-only">: {{ $todo['title'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            @if($vouchers !== null)
                <x-ui.card :title="__('Senaste verifikationer')" class="min-w-0">
                    <x-slot:action>
                        <x-ui.link :href="route('accounting.vouchers.index')">{{ __('Visa alla') }}</x-ui.link>
                    </x-slot:action>

                    @if($vouchers === [])
                        <x-ui.empty-state :title="__('Inga bokförda verifikationer ännu')">
                            {{ __('När du bokför en verifikation, skickar en faktura eller läser in en SIE-fil syns den här.') }}
                        </x-ui.empty-state>
                    @else
                        <ul class="-my-3 divide-y divide-gray-100" data-vouchers>
                            @foreach($vouchers as $voucher)
                                @php($money = App\Support\Money\Money::ofOre($voucher['amount']))
                                <li>
                                    <a href="{{ route('accounting.vouchers.show', $voucher['id']) }}" class="-mx-2 flex items-center gap-4 rounded-xl px-2 py-3 hover:bg-paper focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">
                                        <span class="flex h-10 min-w-12 flex-none items-center justify-center rounded-2xl bg-stone-100 px-2 text-sm font-semibold tabular-nums text-gray-700">{{ $voucher['series'] }}{{ $voucher['number'] }}</span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-semibold text-ink">{{ $voucher['description'] }}</span>
                                            <span class="block text-sm tabular-nums text-gray-600">{{ $voucher['date'] }}</span>
                                        </span>
                                        <span @class([
                                            'flex-none whitespace-nowrap text-right font-semibold tabular-nums',
                                            'text-green-700' => $voucher['signed'] && $voucher['amount'] > 0,
                                            'text-accent-700' => $voucher['signed'] && $voucher['amount'] < 0,
                                            'text-gray-700' => ! $voucher['signed'] || $voucher['amount'] === 0,
                                        ])>
                                            @if($voucher['signed'] && $voucher['amount'] > 0)
                                                <span class="sr-only">{{ __('In:') }}</span>+{{ $money->format() }}
                                            @elseif($voucher['signed'] && $voucher['amount'] < 0)
                                                <span class="sr-only">{{ __('Ut:') }}</span>−{{ $money->negated()->format() }}
                                            @else
                                                {{ $money->format() }}
                                            @endif
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-4 text-xs text-gray-500">{{ __('Plus är pengar in och minus pengar ut på bank och kassa (konto 1900–1999). Belopp utan tecken rör inga pengar, till exempel en faktura som ännu inte är betald.') }}</p>
                    @endif
                </x-ui.card>
            @endif

            @if($skatteverketAwaiting > 0)
                {{-- Från Skatteverket (ADR-054 stage 4): customer events where Skatteverket waits for the company. --}}
                <x-ui.card :title="__('Från Skatteverket')" class="min-w-0" data-overview-skatteverket>
                    <x-slot:action>
                        <x-ui.badge tone="accent">{{ __('Beta') }}</x-ui.badge>
                    </x-slot:action>
                    <p class="text-sm text-gray-700">{{ $skatteverketAwaiting === 1 ? __('1 händelse från Skatteverket väntar på företaget.') : __(':count händelser från Skatteverket väntar på företaget.', ['count' => $skatteverketAwaiting]) }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Enligt kundhändelserna som senast hämtades från Skatteverket.') }}</p>
                    <div class="mt-3"><x-ui.link :href="route('guide.dates').'#fran-skatteverket'">{{ __('Läs händelserna') }}</x-ui.link></div>
                </x-ui.card>
            @endif

            @if($moneyFigures)
                {{-- Momsbufferten (ADR-051, stage 3): what of the money in the bank is not the company's. --}}
                <x-ui.card :title="__('Pengar som inte är dina')" :description="$company->isVatRegistered() ? __('Moms och skatt som ska till Skatteverket, enligt bokföringen.') : __('Skatt som ska till Skatteverket, enligt bokföringen.')" class="min-w-0" data-overview-money>
                    <x-slot:action>
                        <x-ui.link :href="route('guide.dates').'#momsbufferten'">{{ __('Så räknar vi') }}</x-ui.link>
                    </x-slot:action>
                    <x-guide.money-not-yours :figures="$moneyFigures" :company="$company" :vat-deadlines="$moneyVat" :article-url="$setAsideUrl" />
                </x-ui.card>
            @endif

            @if($nextDates !== [])
                {{-- Nästa datum (ADR-051, stage 2): the next dates on Mina datum. --}}
                <x-ui.card :title="__('Nästa datum')" class="min-w-0" data-next-dates>
                    <x-slot:action>
                        <x-ui.link :href="route('guide.dates')">{{ __('Alla datum') }}</x-ui.link>
                    </x-slot:action>
                    <ul class="-my-3 divide-y divide-gray-100">
                        @foreach($nextDates as $deadline)
                            @php($days = $deadline->daysLeft($today))
                            <li class="flex items-center gap-4 py-3">
                                <span @class(['flex size-12 flex-none flex-col items-center justify-center rounded-2xl leading-none', 'bg-accent-100 text-accent-800' => $days <= 7, 'bg-stone-100 text-gray-800' => $days > 7])>
                                    <span class="text-lg font-bold tabular-nums">{{ $deadline->date->day }}</span>
                                    <span class="text-[11px] font-semibold uppercase">{{ $deadline->date->translatedFormat('M') }}</span>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold text-ink">{{ $deadline->title }}</span>
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600">
                                        <x-guide.deadline-chip :category="App\Domain\Deadlines\DeadlineCategory::of($deadline)" />
                                        @if($days === 0)
                                            {{ __('I dag') }}
                                        @elseif($days === 1)
                                            {{ __('I morgon') }}
                                        @else
                                            {{ __('Om :days dagar', ['days' => $days]) }}
                                        @endif
                                        @if($deadline->isRecommendation) · {{ __('Rekommendation') }} @endif
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
