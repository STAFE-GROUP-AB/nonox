@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500 disabled:bg-stone-100';
    $kr = fn (int $ore): string => \App\Support\Money\Money::ofOre($ore)->format();
    $stateLabel = fn (string $state): string => match ($state) {
        'applies' => __('Ja'),
        'not' => __('Nej'),
        'not_needed' => __('Behöver inte bedömas'),
        default => __('Okänt'),
    };
    $sourceLabel = fn (string $source): string => match ($source) {
        'data' => __('Från uppgifterna i NONOX'),
        'answer' => __('Ert svar'),
        'not_needed' => __('Behöver inte bedömas'),
        default => __('Saknas'),
    };
    $confirmText = $frameworkChanges && $yearsToChange->isNotEmpty()
        ? __('Byta regelverk till :framework från räkenskapsåret :year? Det gäller räkenskapsåren :years. Tidigare år och redan gjorda årsredovisningar behåller sitt regelverk.', ['framework' => $chosen->value, 'year' => $fiscalYear->label(), 'years' => $yearsToChange->map->label()->implode(', ')])
        : __('Spara svaren och principerna för räkenskapsåret :year?', ['year' => $fiscalYear->label()]);
@endphp
<div class="space-y-6">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Välj räkenskapsår') }}">
        @foreach($years as $option)
            <button type="button" wire:key="year-{{ $option->id }}" wire:click="$set('year', {{ $option->id }})" @class([
                'rounded-full px-4 py-2 text-sm font-semibold transition',
                'bg-ink text-white' => $option->id === $fiscalYear->id,
                'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => $option->id !== $fiscalYear->id,
            ]) @if($option->id === $fiscalYear->id) aria-current="true" @endif>{{ $option->label() }} · {{ $option->reporting_framework->value }} @if(! $option->isOpen())<span class="font-normal opacity-70">· {{ __('Stängt') }}</span>@endif</button>
        @endforeach
    </nav>

    <section @class([
        'rounded-3xl px-5 py-4 ring-1',
        'bg-amber-50 ring-amber-300' => $eligibility->status === \App\Domain\AnnualReport\K3EligibilityStatus::K3Required,
        'bg-white ring-stone-200' => $eligibility->status !== \App\Domain\AnnualReport\K3EligibilityStatus::K3Required,
    ]) aria-labelledby="framework-status-title" aria-live="polite" data-eligibility-status="{{ $eligibility->status->value }}">
        <h2 id="framework-status-title" class="font-semibold text-ink">{{ $eligibility->status->label() }}</h2>
        <p class="mt-1 text-sm text-gray-800">{{ $eligibility->summary }}</p>
        <p class="mt-1 text-sm text-gray-700">{{ __('Räkenskapsåret :year har i dag :framework.', ['year' => $fiscalYear->label(), 'framework' => $fiscalYear->reporting_framework->label()]) }}</p>
        @if($eligibility->reasons !== [])
            <h3 class="mt-3 text-sm font-semibold text-ink">{{ __('Därför ska företaget tillämpa K3') }}</h3>
            <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-gray-800">
                @foreach($eligibility->reasons as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        @endif
        @if($eligibility->missing !== [])
            <h3 class="mt-3 text-sm font-semibold text-ink">{{ __('Det här saknas') }}</h3>
            <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-gray-800" data-missing>
                @foreach($eligibility->missing as $missing)
                    <li>{{ $missing }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    <form wire:submit="save" wire:confirm="{{ $confirmText }}" class="space-y-6">
        <x-ui.card :title="__('1. Regelverk för året')" :description="__('Valet gäller det valda räkenskapsåret och de år som följer. Ägare och administratörer kan byta regelverk; ett stängt år eller ett år med fastställd årsredovisning kan inte ändras.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label for="framework_choice" value="{{ __('Regelverk från och med :year', ['year' => $fiscalYear->label()]) }}" />
                    <select id="framework_choice" class="{{ $input }}" wire:model.live="framework" @disabled(! $canChangeFramework) aria-describedby="framework_choice_help">
                        @foreach($frameworks as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                    <p id="framework_choice_help" class="mt-1 text-xs text-gray-600">
                        @if(! $canChangeYear)
                            {{ __('Regelverket kan inte bytas från det här året: året eller ett senare år är stängt eller har en fastställd årsredovisning.') }}
                        @elseif(! $canChangeFramework)
                            {{ __('Bara ägare och administratörer kan byta regelverk.') }}
                        @else
                            {{ __('K3 får alltid väljas. K2 får väljas bara när villkoren nedan är uppfyllda.') }}
                        @endif
                    </p>
                    <x-input-error for="reporting_framework" class="mt-2" />
                </div>
                @if($frameworkChanges && $canChangeFramework)
                    <div>
                        <x-label for="framework_reason" value="{{ __('Varför byter företaget regelverk? (frivilligt)') }}" />
                        <textarea id="framework_reason" rows="2" maxlength="2000" class="{{ $input }}" wire:model="reason" aria-describedby="framework_reason_help"></textarea>
                        <p id="framework_reason_help" class="mt-1 text-xs text-gray-600">{{ __('Sparas med räkenskapsåret. Skriv inte personuppgifter.') }}</p>
                        <x-input-error for="reason" class="mt-2" />
                    </div>
                @endif
            </div>

            @if($k2Blocked)
                <p class="mt-4 text-sm font-medium text-red-700" role="alert">{{ __('K2 kan inte väljas: företaget ska tillämpa K3 för det här året.') }}</p>
            @elseif($frameworkChanges && $conditions)
                <div class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                    <p class="whitespace-pre-line">{{ $conditions }}</p>
                    <label class="mt-3 flex items-start gap-3">
                        <x-checkbox wire:model="frameworkConfirmed" class="mt-0.5" />
                        <span>{{ __('Företaget uppfyller villkoren ovan. Är du osäker, fråga din redovisningskonsult innan du väljer.') }}</span>
                    </label>
                    <x-input-error for="framework_confirmed" class="mt-2" />
                </div>
            @endif

            @if($chosen === \App\Domain\Company\ReportingFramework::K3)
                <p class="mt-4 text-sm text-gray-700">{{ __('K3-årsredovisningen är under uppbyggnad i NONOX. Bokföring, bokslut och inkomstdeklaration fungerar som vanligt.') }}</p>
            @endif
        </x-ui.card>

        @if($eligibility->size)
            <x-ui.card :title="__('2. Företagets storlek')" :description="__('Storleken bedöms på de två räkenskapsår som närmast föregår året. Ett år i NONOX läses ur bokföringen och årsredovisningen; ett tidigare år fylls i av er.')">
                <p class="text-sm text-gray-800">{{ $eligibility->size->class->label() }}: {{ $eligibility->size->reason }}</p>
                @if($eligibility->size->years !== [])
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">{{ __('Storleksuppgifter per räkenskapsår') }}</caption>
                            <thead>
                                <tr class="text-left text-gray-600">
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Räkenskapsår') }}</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">{{ __('Källa') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Medelantal anställda') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Balansomslutning') }}</th>
                                    <th scope="col" class="py-2 text-right font-medium">{{ __('Nettoomsättning') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200">
                                @foreach($eligibility->size->years as $figures)
                                    <tr wire:key="size-{{ $figures->key() }}">
                                        <th scope="row" class="py-2 pr-4 text-left font-medium text-ink">{{ $figures->label() }}</th>
                                        <td class="py-2 pr-4 text-gray-700">{{ $figures->source === 'ledger' ? __('Bokföringen i NONOX') : __('Ifyllt av er') }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $figures->averageEmployeesText() ?? __('saknas') }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $kr($figures->balanceSheetTotalOre) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ $kr($figures->netTurnoverOre) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if($eligibility->missingSizeYear && $canEnterFigures)
                    <div class="mt-6 rounded-2xl bg-stone-50 p-4 ring-1 ring-stone-200" data-prior-figures>
                        <h3 class="text-sm font-semibold text-ink">{{ __('Fyll i uppgifterna för året före företagets första år i NONOX') }}</h3>
                        <p class="mt-1 text-xs text-gray-600">{{ __('Hämta beloppen ur årsredovisningen för året. Ange medelantalet anställda exakt, med högst två decimaler; NONOX avrundar själv.') }}</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-5">
                            <div>
                                <x-label for="prior_start" value="{{ __('Första dag') }}" />
                                <input id="prior_start" type="date" class="{{ $input }}" wire:model="prior.start_date" placeholder="{{ $eligibility->missingSizeYear['start_date'] }}">
                                <x-input-error for="start_date" class="mt-1" />
                            </div>
                            <div>
                                <x-label for="prior_end" value="{{ __('Sista dag') }}" />
                                <input id="prior_end" type="date" class="{{ $input }}" wire:model="prior.end_date" placeholder="{{ $eligibility->missingSizeYear['end_date'] }}">
                                <x-input-error for="end_date" class="mt-1" />
                            </div>
                            <div>
                                <x-label for="prior_fte" value="{{ __('Medelantal anställda') }}" />
                                <input id="prior_fte" type="text" inputmode="decimal" class="{{ $input }} tabular-nums" wire:model="prior.average_employees_fte">
                                <x-input-error for="average_employees_fte" class="mt-1" />
                            </div>
                            <div>
                                <x-label for="prior_balance" value="{{ __('Balansomslutning') }}" />
                                <input id="prior_balance" type="text" inputmode="decimal" class="{{ $input }} tabular-nums" wire:model="prior.balance_sheet_total">
                                <x-input-error for="balance_sheet_total" class="mt-1" />
                            </div>
                            <div>
                                <x-label for="prior_turnover" value="{{ __('Nettoomsättning') }}" />
                                <input id="prior_turnover" type="text" inputmode="decimal" class="{{ $input }} tabular-nums" wire:model="prior.net_turnover">
                                <x-input-error for="net_turnover" class="mt-1" />
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-gray-600">{{ __('Året som saknas är :from–:to.', ['from' => $eligibility->missingSizeYear['start_date'], 'to' => $eligibility->missingSizeYear['end_date']]) }}</p>
                        <x-secondary-button type="button" class="mt-3" wire:click="savePriorFigures" wire:loading.attr="disabled">{{ __('Spara storleksuppgifterna') }}</x-secondary-button>
                    </div>
                @endif
            </x-ui.card>
        @endif

        @if($eligibility->items !== [])
            <x-ui.card :title="__('3. Villkoren för K2')" :description="__('Det NONOX kan se i uppgifterna visas direkt. Resten svarar ni på; NONOX svarar aldrig åt er. Svaren gäller det valda räkenskapsåret.')">
                <ul class="divide-y divide-stone-200">
                    @foreach($eligibility->items as $item)
                        <li wire:key="item-{{ $item['key'] }}" class="py-4" data-item="{{ $item['key'] }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="font-semibold text-ink">{{ $item['label'] }} <span class="font-normal text-gray-600">· {{ $item['rule'] }}</span></p>
                                <p class="text-sm text-gray-700">{{ $stateLabel($item['state']) }} · {{ $sourceLabel($item['source']) }}</p>
                            </div>
                            @if($item['question'])
                                <fieldset class="mt-2" @disabled(! $canAnswer)>
                                    <legend class="text-sm text-gray-800">{{ $item['question']->question($eligibility->firstYearStart) }}</legend>
                                    @if($item['text'] !== '')
                                        <p class="mt-1 text-xs text-gray-600">{{ $item['text'] }}</p>
                                    @endif
                                    <div class="mt-2 flex flex-wrap gap-4">
                                        @foreach(['yes' => __('Ja'), 'no' => __('Nej')] as $value => $label)
                                            <label class="flex min-h-11 items-center gap-2 text-sm text-gray-800 sm:min-h-0">
                                                <input type="radio" class="border-stone-300 text-ink focus:ring-accent-500" name="answer-{{ $item['key'] }}" value="{{ $value }}" wire:model.live="answers.{{ $item['key'] }}">
                                                {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @elseif($item['text'] !== '')
                                <p class="mt-1 text-sm text-gray-700">{{ $item['text'] }}</p>
                            @endif

                            @if($item['key'] === 'k2_1_1b_b' && $eligibility->building)
                                <fieldset class="mt-3" @disabled(! $canAnswer) data-building>
                                    <legend class="text-sm text-gray-800">{{ __('Vilka intäktskonton för :year genereras av byggnaderna?', ['year' => $eligibility->building['year']]) }}</legend>
                                    <p class="mt-1 text-xs text-gray-600">{{ __('Räkna hyror för bostäder och lokaler och rumsuthyrning i hotell. En byggnad för tillverkning, varuförsäljning eller enbart tjänster, som en restaurang, räknas inte (BFN:s kommentar till punkt 1.1B b).') }}</p>
                                    <ul class="mt-2 space-y-1">
                                        @foreach($eligibility->building['accounts'] as $account)
                                            <li wire:key="building-{{ $account['number'] }}">
                                                <label class="flex items-center gap-2 text-sm text-gray-800">
                                                    <input type="checkbox" class="rounded-sm border-stone-300 text-ink focus:ring-accent-500" value="{{ $account['number'] }}" wire:model.live="buildingAccounts">
                                                    <span class="tabular-nums">{{ $account['number'] }}</span> {{ $account['name'] }} <span class="ml-auto tabular-nums">{{ $kr($account['amount_ore']) }}</span>
                                                </label>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <label class="mt-3 flex items-start gap-2 text-sm text-gray-800">
                                        <x-checkbox wire:model.live="buildingClassified" class="mt-0.5" />
                                        <span>{{ __('Klassificeringen är klar (lämna alla konton omarkerade om inga intäkter kommer från byggnader).') }}</span>
                                    </label>
                                    <p class="mt-2 text-sm text-gray-700 tabular-nums">{{ __('Nettoomsättning :net, varav från byggnader :building.', ['net' => $kr($eligibility->building['net_sales_ore']), 'building' => $kr($eligibility->building['building_ore'])]) }}</p>
                                </fieldset>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        @if($chosen === \App\Domain\Company\ReportingFramework::K3)
            <x-ui.card :title="__('4. Redovisningsprinciper i K3')" :description="$firstK3 ? __('Principerna sparas från det första räkenskapsåret med K3 och gäller sedan de följande åren.') : __('Principerna sattes det första året med K3 och gäller även det här året. Ett byte av princip stöds inte ännu i NONOX.')">
                @if($missingPolicies !== [])
                    <p class="mb-3 text-sm text-gray-800" data-missing-policies>{{ __('Saknas: :policies.', ['policies' => collect($missingPolicies)->map->label()->implode(', ')]) }}</p>
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach($policyKeys as $key)
                        <div wire:key="policy-{{ $key->value }}">
                            <x-label for="policy_{{ $key->value }}" value="{{ $key->label() }}" />
                            <select id="policy_{{ $key->value }}" class="{{ $input }}" wire:model.live="policies.{{ $key->value }}" @disabled(! $firstK3 || ! $canAnswer) aria-describedby="policy_{{ $key->value }}_help">
                                <option value="">{{ __('Välj') }}</option>
                                @foreach($key->values() as $value)
                                    <option value="{{ $value }}">{{ $key->valueLabel($value) }}</option>
                                @endforeach
                            </select>
                            <p id="policy_{{ $key->value }}_help" class="mt-1 text-xs text-gray-600">{{ $key->question() }}</p>
                            @php($refusal = $key->valueOf((string) ($this->policies[$key->value] ?? ''))?->refusal())
                            @if($refusal)
                                <p class="mt-1 text-xs text-amber-900">{{ $refusal }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                <x-input-error for="policies" class="mt-2" />
                <x-input-error for="value" class="mt-2" />
            </x-ui.card>
        @endif

        <x-ui.card :title="__('Det här händer när du sparar')">
            @if($changes === [])
                <p class="text-sm text-gray-700" data-changes>{{ __('Inget ändras ännu.') }}</p>
            @else
                <ul class="list-disc space-y-1 pl-5 text-sm text-gray-800" data-changes>
                    @foreach($changes as $change)
                        <li>{{ $change }}</li>
                    @endforeach
                </ul>
            @endif
            <x-input-error for="answers" class="mt-2" />
            @if($canAnswer || $canChangeFramework)
                <x-button class="mt-4" wire:loading.attr="disabled" :disabled="$k2Blocked || $changes === []">{{ __('Spara') }}</x-button>
            @endif
        </x-ui.card>
    </form>
</div>
