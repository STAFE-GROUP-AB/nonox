@php
    use App\Domain\Closing\DeferredTaxProposal;
    use App\Models\DeferredTaxItem;
    use App\Support\Money\Money;

    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kinds = [
        DeferredTaxItem::BUILDING_COMPONENTS => __('Byggnad'),
        DeferredTaxItem::OTHER_ASSET => __('Annan tillgång'),
        DeferredTaxItem::OTHER_LIABILITY => __('Skuld'),
        DeferredTaxItem::LOSS_CARRY_FORWARD => __('Underskottsavdrag'),
    ];
    $natures = [
        DeferredTaxProposal::TAXABLE => __('Skattepliktig'),
        DeferredTaxProposal::DEDUCTIBLE => __('Avdragsgill'),
        DeferredTaxProposal::NONE => __('Ingen skillnad'),
    ];
    $carryingSources = [
        DeferredTaxProposal::CARRYING_LEDGER => __('bokföringen'),
        DeferredTaxProposal::CARRYING_REGISTER => __('anläggningsregistret'),
        DeferredTaxProposal::CARRYING_ENTERED => __('inskrivet'),
    ];
    $taxSources = [
        DeferredTaxItem::SOURCE_ENTERED => __('inskrivet'),
        DeferredTaxItem::SOURCE_INK2 => __('inkomstdeklarationen'),
        DeferredTaxItem::SOURCE_REGISTER => __('anläggningsregistret'),
    ];
    $open = $fiscalYear->isOpen() && $entry === null && $canManage && $refusals === [];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Välj räkenskapsår') }}">
            @foreach($years as $option)
                <button type="button" wire:key="year-{{ $option->id }}" wire:click="$set('year', {{ $option->id }})" @class([
                    'rounded-full px-4 py-2 text-sm font-semibold transition',
                    'bg-ink text-white' => $option->id === $fiscalYear->id,
                    'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => $option->id !== $fiscalYear->id,
                ]) @if($option->id === $fiscalYear->id) aria-current="true" @endif>{{ $option->label() }}</button>
            @endforeach
        </nav>
        <a href="{{ route('accounting.closing', ['ar' => $fiscalYear->id]) }}" wire:navigate class="text-sm font-medium text-accent-700 hover:underline">{{ __('Tillbaka till bokslutet') }}</a>
    </div>

    @if($refusals !== [])
        <x-ui.empty-state :title="__('Ingen uppskjuten skatt för året')" data-deferred-tax-refused>
            {{ implode(' ', $refusals) }}
        </x-ui.empty-state>
    @else
        <x-ui.card :title="__('Poster')" :description="__('Lägg upp varje tillgång eller skuld där det redovisade värdet skiljer sig från det skattemässiga, till exempel en byggnad som skrivs av snabbare i deklarationen än i bokföringen. Obeskattade reserver, som periodiseringsfonder, är aldrig en post: i ett aktiebolag redovisas de brutto, utan uppskjuten skatt.')">
            <x-slot:action><x-ui.badge tone="accent">{{ __('Beta') }}</x-ui.badge></x-slot:action>

            @if($proposal->lines === [])
                <x-ui.empty-state class="mt-4" :title="__('Inga poster ännu')">
                    {{ __('Utan poster blir den uppskjutna skatten noll. Lägg till en post nedan, eller bekräfta att företaget inte har några temporära skillnader.') }}
                    @if($open && $previousHasItems)
                        <x-slot:action>
                            <x-button type="button" wire:click="copyPrevious" wire:loading.attr="disabled" data-deferred-tax-copy>{{ __('Hämta posterna från föregående år') }}</x-button>
                        </x-slot:action>
                    @endif
                </x-ui.empty-state>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm" data-deferred-tax-items>
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Post') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Redovisat värde') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Skattemässigt värde') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Temporär skillnad') }}</th>
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Slag') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Uppskjuten skatt') }}</th>
                                @if($open)<th scope="col" class="py-2"><span class="sr-only">{{ __('Ändra') }}</span></th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            @foreach($proposal->lines as $line)
                                <tr wire:key="item-{{ $line['item']->id }}">
                                    <td class="py-2 pr-4">
                                        <p class="font-medium text-ink">{{ $line['item']->description }}</p>
                                        <p class="text-xs text-gray-500">{{ $kinds[$line['item']->kind] }}@if($line['item']->account_from !== null) · {{ __('konto :from–:to', ['from' => $line['item']->account_from, 'to' => $line['item']->account_to]) }}@endif</p>
                                        @if($line['item']->tax_value_needs_review)<p class="text-xs font-medium text-amber-800">{{ __('Hämtad från föregående år – kontrollera det skattemässiga värdet') }}</p>@endif
                                    </td>
                                    <td class="py-2 pr-4 text-right">
                                        @if($line['carrying_ore'] === null)
                                            <span class="text-amber-800">{{ __('saknas') }}</span>
                                        @else
                                            <x-ui.amount :money="Money::ofOre($line['carrying_ore'])" />
                                        @endif
                                        <p class="text-xs text-gray-500">{{ $carryingSources[$line['carrying_source']] }}</p>
                                    </td>
                                    <td class="py-2 pr-4 text-right">
                                        <x-ui.amount :money="Money::ofOre($line['tax_value_ore'])" />
                                        <p class="text-xs text-gray-500">{{ $taxSources[$line['item']->tax_value_source] }}</p>
                                    </td>
                                    <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($line['difference_ore'])" /></td>
                                    <td class="py-2 pr-4">{{ $natures[$line['nature']] }}</td>
                                    <td class="py-2 pr-4 text-right">
                                        @if($line['nature'] === DeferredTaxProposal::DEDUCTIBLE)
                                            <x-ui.amount :money="Money::ofOre($line['asset_ore'])" />
                                            <p class="text-xs text-gray-500">{{ __('fordran, beräknad :gross', ['gross' => Money::ofOre($line['gross_ore'])->format()]) }}</p>
                                        @else
                                            <x-ui.amount :money="Money::ofOre($line['liability_ore'])" />
                                            @if($line['nature'] === DeferredTaxProposal::TAXABLE)<p class="text-xs text-gray-500">{{ __('skuld') }}</p>@endif
                                        @endif
                                    </td>
                                    @if($open)
                                        <td class="py-2 text-right whitespace-nowrap">
                                            <button type="button" wire:click="edit({{ $line['item']->id }})" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Ändra') }}</button>
                                            <button type="button" wire:click="remove({{ $line['item']->id }})" wire:confirm="{{ __('Ta bort posten ”:item”? Den uppskjutna skatten räknas om utan den.', ['item' => $line['item']->description]) }}" class="ml-3 text-sm font-medium text-gray-600 hover:underline">{{ __('Ta bort') }}</button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($open)
                <form wire:submit="save" class="mt-6 grid gap-4 border-t border-stone-200 pt-6 sm:grid-cols-2" data-deferred-tax-form>
                    <p class="font-semibold text-ink sm:col-span-2">{{ $editing === null ? __('Lägg till en post') : __('Ändra posten') }}</p>
                    <x-ui.field for="deferred_tax_kind" :label="__('Sort')" :error="'kind'">
                        <select id="deferred_tax_kind" class="{{ $input }} mt-1" wire:model.live="kind">
                            @foreach($kinds as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="deferred_tax_description" :label="__('Beskrivning')" :error="'description'">
                        <input id="deferred_tax_description" type="text" maxlength="120" class="{{ $input }} mt-1" wire:model="description">
                    </x-ui.field>
                    @if($kind !== DeferredTaxItem::LOSS_CARRY_FORWARD)
                        <x-ui.field for="deferred_tax_account_from" :label="__('Konton, från och till')" :error="'account_from'" :hint="__('Det redovisade värdet läses från kontonas utgående balans. Lämna tomt för att skriva in värdet själv.')">
                            <div class="mt-1 flex gap-2">
                                <input id="deferred_tax_account_from" type="text" inputmode="numeric" maxlength="4" class="{{ $input }} w-28" wire:model="accountFrom" aria-label="{{ __('Från konto') }}">
                                <input id="deferred_tax_account_to" type="text" inputmode="numeric" maxlength="4" class="{{ $input }} w-28" wire:model="accountTo" aria-label="{{ __('Till konto') }}">
                            </div>
                        </x-ui.field>
                        <x-ui.field for="deferred_tax_carrying" :label="__('Redovisat värde, kr')" :error="'carrying'" :hint="__('Bara om du inte anger konton.')">
                            <input id="deferred_tax_carrying" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="carrying">
                        </x-ui.field>
                    @endif
                    <x-ui.field for="deferred_tax_tax_value" :label="$kind === DeferredTaxItem::LOSS_CARRY_FORWARD ? __('Underskott att dra av, kr') : __('Skattemässigt värde, kr')" :error="'tax_value'">
                        <input id="deferred_tax_tax_value" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="taxValue">
                    </x-ui.field>
                    <x-ui.field for="deferred_tax_source" :label="__('Källa för det skattemässiga värdet')" :error="'tax_value_source'">
                        <select id="deferred_tax_source" class="{{ $input }} mt-1" wire:model="taxValueSource">
                            <option value="{{ DeferredTaxItem::SOURCE_ENTERED }}">{{ __('Eget underlag') }}</option>
                            <option value="{{ DeferredTaxItem::SOURCE_INK2 }}">{{ __('Inkomstdeklarationen') }}</option>
                        </select>
                    </x-ui.field>
                    <x-ui.field for="deferred_tax_recognised" :label="__('Uppskjuten skattefordran att ta upp, kr')" :error="'recognised'" :hint="__('NONOX föreslår aldrig en skattefordran. Ta bara upp det belopp som det finns övertygande skäl att kvitta mot framtida skattepliktiga överskott (K3 punkt 29.29). Lämna tomt för 0.')">
                        <input id="deferred_tax_recognised" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="recognised">
                    </x-ui.field>
                    <x-ui.field for="deferred_tax_reason" :label="__('Skäl till att fordran tas upp')" :error="'recognition_reason'">
                        <textarea id="deferred_tax_reason" rows="2" maxlength="1000" class="{{ $input }} mt-1" wire:model="recognitionReason"></textarea>
                    </x-ui.field>
                    <div class="flex flex-wrap gap-3 sm:col-span-2">
                        <x-button wire:loading.attr="disabled">{{ $editing === null ? __('Lägg till posten') : __('Spara posten') }}</x-button>
                        @if($editing !== null)
                            <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                        @endif
                    </div>
                </form>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Uppskjuten skatt vid balansdagen')">
            @if($proposal->rateBp !== null)
                <p class="text-sm text-gray-700">{{ __('Skattesats :rate, den som gäller för räkenskapsår som börjar :date (K3 punkt 29.25). Beloppen nuvärdeberäknas inte.', ['rate' => DeferredTaxProposal::percent($proposal->rateBp), 'date' => $fiscalYear->end_date->addDay()->toDateString()]) }}</p>
            @endif
            <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2" data-deferred-tax-totals>
                <dt class="text-gray-600">{{ __('Uppskjuten skatteskuld, konto 2240') }}</dt>
                <dd class="text-right font-semibold"><x-ui.amount :money="Money::ofOre($proposal->targetLiabilityOre())" /></dd>
                <dt class="text-gray-600">{{ __('Uppskjuten skattefordran, konto 1370') }}</dt>
                <dd class="text-right font-semibold"><x-ui.amount :money="Money::ofOre($proposal->targetAssetOre())" /></dd>
                <dt class="text-gray-600">{{ __('Vid årets början: skuld och fordran') }}</dt>
                <dd class="text-right"><x-ui.amount :money="Money::ofOre($proposal->openingLiabilityOre)" /> / <x-ui.amount :money="Money::ofOre($proposal->openingAssetOre)" /></dd>
                <dt class="text-gray-600">{{ __('Förändring sedan förra balansdagen') }}</dt>
                <dd class="text-right"><x-ui.amount :money="Money::ofOre($proposal->changeSinceOpeningOre())" /></dd>
                <dt class="text-gray-600">{{ __('Att bokföra på konto 8940 (kostnad positiv)') }}</dt>
                <dd class="text-right font-semibold" data-deferred-tax-expense><x-ui.amount :money="Money::ofOre($proposal->expenseOre())" /></dd>
            </dl>

            @if($proposal->blockers !== [])
                <ul class="mt-4 space-y-1 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-300" data-deferred-tax-blockers>
                    @foreach($proposal->blockers as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            @endif

            @if($entry !== null)
                <p class="mt-4 text-sm text-gray-700" data-deferred-tax-booked>{{ $entry->amount_ore->isZero() ? __('Den uppskjutna skatten är bekräftad. Inget behövde bokföras.') : __(':amount är bokfört på 8940. Posterna är låsta; rätta verifikationen om något blev fel.', ['amount' => $entry->amount_ore->format()]) }}</p>
            @elseif($open && $proposal->blockers === [])
                @if($proposal->reversesEverything())
                    <label class="mt-4 flex items-start gap-2 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-300" data-deferred-tax-reverse-all>
                        <input type="checkbox" class="mt-0.5 rounded border-stone-300" wire:model.live="reverseAll">
                        <span>{{ __('Företaget har inga temporära skillnader kvar. Uppskjuten skatteskuld :liability och uppskjuten skattefordran :asset ska återföras helt.', ['liability' => Money::ofOre($proposal->bookedLiabilityOre)->format(), 'asset' => Money::ofOre($proposal->bookedAssetOre)->format()]) }}</span>
                    </label>
                @endif
                @if($proposal->liabilityOre() > 0 && $proposal->assetOre() > 0)
                    <label class="mt-4 flex items-start gap-2 text-sm text-gray-700">
                        <input type="checkbox" class="mt-0.5 rounded border-stone-300" wire:model.live="setOff">
                        <span>{{ __('Företaget har legal rätt att kvitta skulden mot fordran, så de redovisas netto (K3 punkt 29.32).') }}</span>
                    </label>
                @endif
                <x-button type="button" class="mt-4" wire:click="confirm('{{ $proposal->fingerprint() }}')" wire:loading.attr="disabled" wire:target="setOff,reverseAll,confirm" wire:confirm="{{ $proposal->confirmationText() }}">{{ __('Bekräfta och bokför uppskjuten skatt') }}</x-button>
            @endif
        </x-ui.card>
    @endif
</div>
