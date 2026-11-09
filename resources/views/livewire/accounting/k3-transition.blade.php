@php
    use App\Actions\AnnualReport\K3TransitionItems;
    use App\Domain\AnnualReport\K3ForbiddenRestatement;
    use App\Domain\AnnualReport\K3TransitionProposal;
    use App\Domain\Closing\DeferredTaxProposal;
    use App\Models\DeferredTaxItem;
    use App\Models\K3TransitionItem;
    use App\Support\Money\Money;

    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kinds = [
        K3TransitionItem::DEFERRED_TAX => __('Uppskjuten skatt'),
        K3TransitionItem::PROVISION => __('Avsättning'),
        K3TransitionItem::OTHER => __('Annan justering'),
    ];
    $deferredKinds = [
        DeferredTaxItem::BUILDING_COMPONENTS => __('Byggnad'),
        DeferredTaxItem::OTHER_ASSET => __('Annan tillgång'),
        DeferredTaxItem::OTHER_LIABILITY => __('Skuld'),
        DeferredTaxItem::LOSS_CARRY_FORWARD => __('Underskottsavdrag'),
    ];
    $natures = [
        K3TransitionProposal::TAXABLE => __('Skattepliktig'),
        K3TransitionProposal::DEDUCTIBLE => __('Avdragsgill'),
        K3TransitionProposal::NONE => __('Ingen skillnad'),
        K3TransitionProposal::RELIEF => __('Lättnadsregel 35.27'),
    ];
    $buildingStatus = [
        'split' => __('Uppdelad per övergångsdagen'),
        'split_later' => __('Uppdelad från ett senare datum'),
        'not_split' => __('Inte uppdelad'),
    ];
    $open = $proposal !== null && ! $proposal->isBooked() && $canManage && $fiscalYear->isOpen();
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
        <x-ui.empty-state :title="__('Ingen övergång till K3 för året')" data-k3-transition-refused>
            {{ implode(' ', $refusals) }}
        </x-ui.empty-state>
    @else
        <x-ui.card :title="__('Justeringar vid övergången')" :description="__('Övergångsdagen är :date, första dagen i det första året med K3. Lägg upp varje skillnad mellan K2 och K3 i ingångsbalansräkningen: uppskjuten skatt på temporära skillnader, avsättningar som K3 kräver och andra poster som räknas om. Skillnaderna bokförs direkt mot eget kapital (K3 punkt 35.11).', ['date' => $proposal->transitionDate->toDateString()])">
            <x-slot:action><x-ui.badge tone="accent">{{ __('Beta') }}</x-ui.badge></x-slot:action>

            <p class="mt-2 text-sm text-gray-700">{{ __('Vissa omräkningar är förbjudna vid övergången och tas inte emot: :list.', ['list' => implode('; ', array_map(fn (K3ForbiddenRestatement $case): string => $case->label().' ('.$case->rule().')', K3ForbiddenRestatement::cases()))]) }}</p>

            @if($proposal->lines === [])
                <x-ui.empty-state class="mt-4" :title="__('Inga justeringar ännu')">
                    {{ __('Utan justeringar ändras inte eget kapital. Lägg till en post nedan, eller bekräfta övergången utan justeringar om K2 och K3 ger samma värden.') }}
                </x-ui.empty-state>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm" data-k3-transition-items>
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Post') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Redovisat värde') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Skattemässigt värde') }}</th>
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Slag') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Påverkan på eget kapital') }}</th>
                                @if($open)<th scope="col" class="py-2"><span class="sr-only">{{ __('Ändra') }}</span></th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            @foreach($proposal->lines as $line)
                                @php($item = $line['item'])
                                <tr wire:key="transition-item-{{ $item->id }}">
                                    <td class="py-2 pr-4">
                                        <p class="font-medium text-ink">{{ $item->description }}</p>
                                        <p class="text-xs text-gray-500">
                                            {{ $kinds[$item->kind] }}
                                            @if($item->account_from !== null) · {{ __('konto :from–:to', ['from' => $item->account_from, 'to' => $item->account_to]) }}@endif
                                            @if($item->account !== null) · {{ __('konto :account', ['account' => $item->account]) }}@endif
                                            @if($item->k3_reference !== null) · {{ $item->k3_reference }}@endif
                                        </p>
                                        @if($item->relief_35_27)<p class="text-xs text-gray-600">{{ __('Skäl: :reason', ['reason' => $item->relief_reason]) }}</p>@endif
                                    </td>
                                    <td class="py-2 pr-4 text-right">
                                        @if($line['carrying_ore'] !== null)<x-ui.amount :money="Money::ofOre($line['carrying_ore'])" />@endif
                                    </td>
                                    <td class="py-2 pr-4 text-right">
                                        @if($line['tax_value_ore'] !== null)<x-ui.amount :money="Money::ofOre($line['tax_value_ore'])" />@endif
                                    </td>
                                    <td class="py-2 pr-4">
                                        @if($line['nature'] !== null)
                                            {{ $natures[$line['nature']] }}
                                            @if($line['nature'] === K3TransitionProposal::DEDUCTIBLE)<p class="text-xs text-gray-500">{{ __('fordran, beräknad :gross', ['gross' => Money::ofOre($line['gross_ore'])->format()]) }}</p>@endif
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($line['equity_ore'])" /></td>
                                    @if($open)
                                        <td class="py-2 text-right whitespace-nowrap">
                                            <button type="button" wire:click="edit({{ $item->id }})" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Ändra') }}</button>
                                            <button type="button" wire:click="remove({{ $item->id }})" wire:confirm="{{ __('Ta bort posten ”:item”? Övergången räknas om utan den.', ['item' => $item->description]) }}" class="ml-3 text-sm font-medium text-gray-600 hover:underline">{{ __('Ta bort') }}</button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($open)
                <form wire:submit="save" class="mt-6 grid gap-4 border-t border-stone-200 pt-6 sm:grid-cols-2" data-k3-transition-form>
                    <p class="font-semibold text-ink sm:col-span-2">{{ $editing === null ? __('Lägg till en justering') : __('Ändra justeringen') }}</p>
                    <x-ui.field for="transition_kind" :label="__('Sort')" :error="'kind'">
                        <select id="transition_kind" class="{{ $input }} mt-1" wire:model.live="kind">
                            @foreach($kinds as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="transition_description" :label="__('Beskrivning')" :error="'description'">
                        <input id="transition_description" type="text" maxlength="120" class="{{ $input }} mt-1" wire:model="description">
                    </x-ui.field>

                    @if($kind === K3TransitionItem::DEFERRED_TAX)
                        <x-ui.field for="transition_deferred_kind" :label="__('Skillnaden gäller')" :error="'deferred_tax_kind'">
                            <select id="transition_deferred_kind" class="{{ $input }} mt-1" wire:model.live="deferredTaxKind">
                                @foreach($deferredKinds as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        @if($deferredTaxKind !== DeferredTaxItem::LOSS_CARRY_FORWARD)
                            <x-ui.field for="transition_account_from" :label="__('Konton, från och till')" :error="'account_from'" :hint="__('Det redovisade värdet läses från kontonas ingående balans på övergångsdagen. Lämna tomt för att skriva in värdet själv.')">
                                <div class="mt-1 flex gap-2">
                                    <input id="transition_account_from" type="text" inputmode="numeric" maxlength="4" class="{{ $input }} w-28" wire:model="accountFrom" aria-label="{{ __('Från konto') }}">
                                    <input id="transition_account_to" type="text" inputmode="numeric" maxlength="4" class="{{ $input }} w-28" wire:model="accountTo" aria-label="{{ __('Till konto') }}">
                                </div>
                            </x-ui.field>
                            <x-ui.field for="transition_carrying" :label="__('Redovisat värde enligt K3, kr')" :error="'carrying'" :hint="__('Bara om du inte anger konton, till exempel för en avsättning som läggs upp vid övergången.')">
                                <input id="transition_carrying" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="carrying">
                            </x-ui.field>
                        @endif
                        <label class="flex items-start gap-2 text-sm text-gray-700 sm:col-span-2">
                            <input type="checkbox" class="mt-0.5 rounded border-stone-300" wire:model.live="relief">
                            <span>{{ __('Uppgifterna kan inte tas fram utan oskälig kostnad. Använd lättnadsregeln i K3 punkt 35.27: ingen uppskjuten skatt redovisas för posten, och det anges i årsredovisningen.') }}</span>
                        </label>
                        @if($relief)
                            <x-ui.field class="sm:col-span-2" for="transition_relief_reason" :label="__('Skäl till att lättnadsregeln används')" :error="'relief_reason'">
                                <textarea id="transition_relief_reason" rows="2" maxlength="1000" class="{{ $input }} mt-1" wire:model="reliefReason"></textarea>
                            </x-ui.field>
                        @else
                            <x-ui.field for="transition_tax_value" :label="$deferredTaxKind === DeferredTaxItem::LOSS_CARRY_FORWARD ? __('Underskott att dra av, kr') : __('Skattemässigt värde, kr')" :error="'tax_value'">
                                <input id="transition_tax_value" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="taxValue">
                            </x-ui.field>
                            <x-ui.field for="transition_recognised" :label="__('Uppskjuten skattefordran att ta upp, kr')" :error="'recognised'" :hint="__('NONOX föreslår aldrig en skattefordran. Lämna tomt för 0.')">
                                <input id="transition_recognised" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="recognised">
                            </x-ui.field>
                            <x-ui.field class="sm:col-span-2" for="transition_recognition_reason" :label="__('Skäl till att fordran tas upp')" :error="'recognition_reason'">
                                <textarea id="transition_recognition_reason" rows="2" maxlength="1000" class="{{ $input }} mt-1" wire:model="recognitionReason"></textarea>
                            </x-ui.field>
                        @endif
                    @else
                        <x-ui.field for="transition_account" :label="__('Konto')" :error="'account'" :hint="$kind === K3TransitionItem::PROVISION ? __('Ett avsättningskonto, 2200–2299.') : __('Ett balanskonto, 1300–2999. Anläggningstillgångar räknas inte om vid övergången.')">
                            <input id="transition_account" type="text" inputmode="numeric" maxlength="4" class="{{ $input }} mt-1" wire:model="account">
                        </x-ui.field>
                        @if($kind === K3TransitionItem::PROVISION)
                            <label class="flex items-start gap-2 text-sm text-gray-700 sm:col-span-2">
                                <input type="checkbox" class="mt-0.5 rounded border-stone-300" wire:model.live="dismantling">
                                <span>{{ __('Avsättningen gäller en skyldighet att montera ned en tillgång eller återställa en plats.') }}</span>
                            </label>
                            @if($dismantling)
                                <p class="rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-300 sm:col-span-2" data-k3-transition-dismantling>{{ __('En skyldighet att montera ned eller återställa kan inte hanteras här ännu. Den ska också räknas in i tillgångens anskaffningsvärde (K3 punkt 17.10 och 35.29). Ta upp den med din redovisningskonsult.') }}</p>
                            @endif
                        @endif
                        <x-ui.field for="transition_amount" :label="__('Belopp, kr')" :error="'amount'">
                            <input id="transition_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="amount">
                        </x-ui.field>
                        @if($kind === K3TransitionItem::OTHER)
                            <x-ui.field for="transition_direction" :label="__('Kontot ska')" :error="'direction'">
                                <select id="transition_direction" class="{{ $input }} mt-1" wire:model="direction">
                                    <option value="{{ K3TransitionItems::DEBIT }}">{{ __('debiteras') }}</option>
                                    <option value="{{ K3TransitionItems::CREDIT }}">{{ __('krediteras') }}</option>
                                </select>
                            </x-ui.field>
                        @endif
                        <x-ui.field for="transition_reference" :label="__('Punkt i K3')" :error="'k3_reference'" :hint="$kind === K3TransitionItem::PROVISION ? __('Frivilligt, till exempel K3 punkt 21.4.') : __('Till exempel K3 punkt 21.4.')">
                            <input id="transition_reference" type="text" maxlength="60" class="{{ $input }} mt-1" wire:model="k3Reference">
                        </x-ui.field>
                    @endif

                    <div class="flex flex-wrap gap-3 sm:col-span-2">
                        <x-button wire:loading.attr="disabled">{{ $editing === null ? __('Lägg till justeringen') : __('Spara justeringen') }}</x-button>
                        @if($editing !== null)
                            <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                        @endif
                    </div>
                </form>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Komponenter')" :description="__('Byggnader räknas inte om bakåt i tiden. Det redovisade värdet på övergångsdagen delas upp på betydande komponenter i anläggningsregistret, och det ger ingen skillnad i eget kapital (K3 punkt 35.18).')">
            @if($proposal->buildings === [])
                <p class="mt-2 text-sm text-gray-700">{{ __('Anläggningsregistret har ingen byggnad.') }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm" data-k3-transition-buildings>
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Byggnad') }}</th>
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Redovisat värde före') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Summa komponenter') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Skillnad i eget kapital') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            @foreach($proposal->buildings as $building)
                                <tr wire:key="building-{{ $building['asset']->id }}">
                                    <td class="py-2 pr-4">{{ $building['asset']->number }} {{ $building['asset']->name }}</td>
                                    <td class="py-2 pr-4">{{ $buildingStatus[$building['status']] }}</td>
                                    <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($building['carrying_before_ore'])" /></td>
                                    <td class="py-2 pr-4 text-right">@if($building['status'] !== 'not_split')<x-ui.amount :money="Money::ofOre($building['carrying_after_ore'])" />@endif</td>
                                    <td class="py-2 pr-4 text-right">@if($building['status'] === 'split')<x-ui.amount :money="Money::zero()" />@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <a href="{{ route('accounting.fixedAssets') }}" wire:navigate class="mt-3 inline-block text-sm font-medium text-accent-700 hover:underline">{{ __('Gå till anläggningsregistret') }}</a>
            @endif
        </x-ui.card>

        @php($sheet = $proposal->reconciliation())
        @if($proposal->warnings !== [])
            <ul class="space-y-1 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-300" data-k3-transition-warnings>
                @foreach($proposal->warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
        <x-ui.card :title="__('Avstämning av eget kapital')" :description="__('K2-värdena per :previous, övergångens justeringar och ingångsbalansräkningen enligt K3 per :date. De ingående balanserna ändras inte: justeringarna bokförs som en verifikation på övergångsdagen.', ['previous' => $proposal->transitionDate->subDay()->toDateString(), 'date' => $proposal->transitionDate->toDateString()])">
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm" data-k3-transition-reconciliation>
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Post i balansräkningen') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Enligt K2') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Justering') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Enligt K3') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        @foreach($sheet['lines'] as $line)
                            <tr wire:key="reconciliation-{{ $line['key'] }}">
                                <td class="py-2 pr-4">{{ $line['label'] }}</td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($line['k2_ore'])" /></td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($line['adjustment_ore'])" /></td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($line['k3_ore'])" /></td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold">
                            <td class="py-2 pr-4">{{ __('Summa eget kapital') }}</td>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($sheet['equity_k2_ore'])" /></td>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($sheet['equity_k3_ore'] - $sheet['equity_k2_ore'])" /></td>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($sheet['equity_k3_ore'])" /></td>
                        </tr>
                        <tr class="font-semibold">
                            <td class="py-2 pr-4">{{ __('Balansomslutning') }}</td>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($sheet['total_k2_ore'])" /></td>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($sheet['total_k3_ore'] - $sheet['total_k2_ore'])" /></td>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($sheet['total_k3_ore'])" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-sm text-gray-700">{{ $proposal->setOff ? __('Uppskjuten skattefordran och skatteskuld visas netto, eftersom kvittningsrätten är bekräftad (K3 punkt 29.32). Verifikationen bokförs ändå brutto.') : __('Uppskjuten skattefordran och skatteskuld visas brutto. De får bara visas netto om företaget har legal rätt att kvitta dem (K3 punkt 29.32).') }}</p>
            @if($open)
                <label class="mt-3 flex items-start gap-2 text-sm text-gray-700">
                    <input type="checkbox" class="mt-0.5 rounded border-stone-300" @checked($proposal->setOff) wire:change="setOff($event.target.checked)" data-k3-transition-set-off>
                    <span>{{ __('Kvittningsrätt för uppskjuten skatt finns: företaget har legal rätt att kvitta uppskjutna skattefordringar mot uppskjutna skatteskulder (K3 punkt 29.32).') }}</span>
                </label>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Upplysningar i årsredovisningen')" :description="__('Texten om övergången och den bristande jämförbarheten som K3-årsredovisningen ska innehålla (K3 punkterna 35.7, 35.31 och 35.33).')">
            <div class="mt-3 space-y-2 text-sm text-gray-800" data-k3-transition-note>
                @foreach($proposal->comparabilityNote() as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Bokföring av övergången')">
            @if($proposal->voucherRows() !== [])
                <div class="mt-2 overflow-x-auto">
                    <table class="min-w-full text-sm" data-k3-transition-voucher>
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Konto') }}</th>
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Text') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Debet') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Kredit') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            @foreach($proposal->voucherRows() as $index => $row)
                                <tr wire:key="voucher-row-{{ $index }}">
                                    <td class="py-2 pr-4">{{ $row['account'] }}</td>
                                    <td class="py-2 pr-4">{{ $row['description'] }}</td>
                                    <td class="py-2 pr-4 text-right">@if($row['amount'] > 0)<x-ui.amount :money="Money::ofOre($row['amount'])" />@endif</td>
                                    <td class="py-2 pr-4 text-right">@if($row['amount'] < 0)<x-ui.amount :money="Money::ofOre(-$row['amount'])" />@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if($proposal->rateBp !== null)
                <p class="mt-3 text-sm text-gray-700">{{ __('Skattesats :rate, den som gäller för räkenskapsår som börjar :date (K3 punkt 29.25).', ['rate' => DeferredTaxProposal::percent($proposal->rateBp), 'date' => $proposal->transitionDate->toDateString()]) }}</p>
            @endif

            @if($proposal->isBooked())
                <p class="mt-4 text-sm text-gray-700" data-k3-transition-booked>{{ $proposal->booked->voucher_id === null ? __('Övergången är bokförd utan verifikation, eftersom inget ändrade bokföringen.') : __('Övergången är bokförd den :date. Posterna är låsta; rätta verifikationen om något blev fel.', ['date' => $proposal->transitionDate->toDateString()]) }}</p>
            @else
                @if($proposal->blockers !== [])
                    <ul class="mt-4 space-y-1 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-300" data-k3-transition-blockers>
                        @foreach($proposal->blockers as $blocker)
                            <li>{{ $blocker }}</li>
                        @endforeach
                    </ul>
                @elseif($open)
                    <x-button type="button" class="mt-4" wire:click="confirm('{{ $proposal->fingerprint() }}')" wire:loading.attr="disabled" wire:target="confirm" wire:confirm="{{ $proposal->confirmationText() }}">{{ __('Bekräfta och bokför övergången') }}</x-button>
                @endif
            @endif
        </x-ui.card>
    @endif
</div>
