@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $status = $agreement->status;
    $S = App\Domain\Pricing\PriceAgreementStatus::class;
    $mode = $item['mode'] ?? 'price';
    $target = $item['target'] ?? 'article';
@endphp

<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.badge :tone="$status->tone()" data-agreement-status>{{ $status->label() }}</x-ui.badge>
            @if($agreement->supersedes)
                <span class="text-sm text-gray-600">{{ __('Ny version av') }} <a href="{{ route('pricing.agreements.show', $agreement->supersedes->id) }}" class="font-medium text-accent-700 underline">{{ $agreement->supersedes->reference() }}</a></span>
            @endif
            @if($supersededBy)
                <span class="text-sm text-gray-600">{{ $status === $S::Superseded ? __('Ersatt av') : __('Ny version på gång:') }} <a href="{{ route('pricing.agreements.show', $supersededBy->id) }}" class="font-medium text-accent-700 underline">{{ $supersededBy->reference() }}</a></span>
            @endif
        </div>

        <dl class="mt-4 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('Kund') }}</dt><dd class="font-medium text-gray-900">{{ $agreement->customer->number ?? '' }} {{ $agreement->customer->name ?? '' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Gäller') }}</dt><dd class="font-medium tabular-nums text-gray-900">{{ $agreement->valid_from->toDateString() }} – {{ $agreement->valid_to?->toDateString() ?? __('tills vidare') }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Valuta') }}</dt><dd class="font-medium text-gray-900">{{ $agreement->currency }}, {{ __('exklusive moms') }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Kampanjer') }}</dt><dd class="font-medium text-gray-900">{{ $agreement->allow_campaigns ? __('En billigare kampanj går före') : __('Avtalets pris gäller') }}</dd></div>
            @if($agreement->terminated_on)
                <div><dt class="text-gray-500">{{ __('Uppsagt') }}</dt><dd class="font-medium tabular-nums text-gray-900">{{ __('Gäller till och med :date', ['date' => $agreement->terminated_on->toDateString()]) }}</dd></div>
            @endif
            @if($agreement->crmAgreement && $crm)
                <div><dt class="text-gray-500">{{ __('Avtalsregistret') }}</dt><dd class="font-medium text-gray-900"><a href="{{ route('crm.agreements.show', $agreement->crmAgreement->id) }}" class="text-accent-700 underline">{{ $agreement->crmAgreement->title }}</a></dd></div>
            @endif
            @if($agreement->activation_method)
                <div class="sm:col-span-2">
                    <dt class="text-gray-500">{{ __('Aktiverat') }}</dt>
                    <dd class="text-gray-900" data-agreement-activation>
                        <span class="font-medium">{{ $agreement->activation_method->label() }}</span>
                        <span class="tabular-nums">{{ $agreement->activated_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>@if($activatedBy) · {{ $activatedBy }}@endif
                        @if($agreement->activation_reason)
                            <span class="block text-gray-600">{{ __('Skäl: :reason', ['reason' => $agreement->activation_reason]) }}</span>
                        @endif
                    </dd>
                </div>
            @endif
        </dl>

        @if($agreement->crmAgreement && $crm)
            <p class="mt-4 rounded-xl bg-accent-50 px-4 py-3 text-sm text-accent-900">{{ __('Avtalet slutar samma dag som avtalet i avtalsregistret, och påminnelserna om förlängning och uppsägning kommer därifrån.') }}</p>
        @endif

        @if($status === $S::AwaitingSignature)
            <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('Avtalet väntar på kundens underskrift och kan inte ändras. Ta tillbaka det till utkast om något ska ändras; länkarna slutar då att gälla.') }}</p>
        @elseif(! $status->isDraft())
            <p class="mt-4 rounded-xl bg-stone-50 px-4 py-3 text-sm text-gray-700">{{ __('Villkoren i ett avtal som har skickats eller gällt ändras aldrig. Gör en ny version med Ändra avtal; den gamla gäller tills den nya börjar.') }}</p>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <a href="{{ route('pricing.agreements.pdf', $agreement->id) }}" class="font-medium text-accent-700 underline" target="_blank" rel="noopener">{{ __('Visa som PDF') }}</a>
            @if($canChangeTerms && ! $editingHead)
                <button type="button" class="font-medium text-accent-700 underline" wire:click="editHead">{{ __('Ändra villkor') }}</button>
            @endif
            @if($canManage && $status->isDraft() && ! $activating)
                <button type="button" class="font-medium text-accent-700 underline" wire:click="startActivation">{{ __('Aktivera utan signering') }}</button>
            @endif
            @if($canManage && $status === $S::AwaitingSignature)
                <button type="button" class="font-medium text-gray-700 underline" wire:click="reopen" wire:confirm="{{ __('Ta tillbaka avtalet till utkast? Alla länkar för signering slutar gälla, och kunden kan inte signera förrän du skickar det igen.') }}">{{ __('Tillbaka till utkast') }}</button>
            @endif
            @if($canManage && in_array($status, [$S::Active, $S::Terminated], true) && ! ($supersededBy && in_array($supersededBy->status, [$S::Draft, $S::AwaitingSignature], true)))
                <button type="button" class="font-medium text-accent-700 underline" wire:click="newVersion">{{ __('Ändra avtal') }}</button>
            @endif
            @if($canManage && $status === $S::Active && ! $terminating)
                <button type="button" class="font-medium text-gray-700 underline" wire:click="startTermination">{{ __('Säg upp') }}</button>
            @endif
            @if($canDelete)
                <button type="button" class="font-medium text-red-700 underline" wire:click="delete" wire:confirm="{{ __('Ta bort utkastet med alla priser? Det går inte att ångra.') }}">{{ __('Ta bort utkastet') }}</button>
            @endif
            <a href="{{ route('pricing.agreements') }}" class="text-gray-600 underline">{{ __('Tillbaka till prisavtal') }}</a>
        </div>
    </x-ui.card>

    @if($editingHead)
        <x-ui.card :title="__('Ändra villkor')">
            <form wire:submit="saveHead" class="grid gap-4 sm:grid-cols-6">
                <x-ui.field for="head_title" :label="__('Rubrik')" error="title" class="sm:col-span-4">
                    <input id="head_title" type="text" class="{{ $input }} mt-1" wire:model="head.title" maxlength="150">
                </x-ui.field>
                <x-ui.field for="head_currency" :label="__('Valuta')" error="currency" class="sm:col-span-2">
                    <input id="head_currency" type="text" class="{{ $input }} mt-1 uppercase" wire:model="head.currency" maxlength="3" autocomplete="off">
                </x-ui.field>
                <x-ui.field for="head_from" :label="__('Gäller från')" error="valid_from" class="sm:col-span-3">
                    <input id="head_from" type="date" class="{{ $input }} mt-1" wire:model="head.valid_from">
                </x-ui.field>
                <x-ui.field for="head_to" :label="__('Gäller till och med (valfritt)')" error="valid_to" class="sm:col-span-3" :hint="__('Tomt betyder tills vidare.')">
                    <input id="head_to" type="date" class="{{ $input }} mt-1" wire:model="head.valid_to" @disabled(($head['crm_agreement_id'] ?? '') !== '')>
                </x-ui.field>
                @if($crmChoices->isNotEmpty())
                    <x-ui.field for="head_crm" :label="__('Koppla till avtal i avtalsregistret (valfritt)')" error="crm_agreement_id" class="sm:col-span-6" :hint="__('Prisavtalet slutar då samma dag som avtalet i avtalsregistret, och påminnelserna kommer därifrån.')">
                        <select id="head_crm" class="{{ $input }} mt-1" wire:model.live="head.crm_agreement_id">
                            <option value="">{{ __('Inget') }}</option>
                            @foreach($crmChoices as $choice)
                                <option value="{{ $choice->id }}">{{ $choice->title }} ({{ $choice->end_date?->toDateString() ?? __('tills vidare') }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <label class="flex items-start gap-2 text-sm text-gray-800 sm:col-span-6">
                    <input type="checkbox" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="head.allow_campaigns">
                    <span>{{ __('En kampanj med lägre pris får gå före avtalets pris') }}</span>
                </label>
                <div class="flex items-center gap-4 sm:col-span-6">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelHead">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($activating)
        <x-ui.card :title="__('Aktivera utan signering')" :description="__('Använd det här när kunden har godkänt avtalet på annat sätt, till exempel skrivit under på papper eller godkänt det skriftligen på annat sätt. Skälet sparas med avtalet, tillsammans med vem som aktiverade det och när. NONOX kontrollerar inte att kunden har godkänt avtalet; aktiveringen registrerar bara att du anger det.')">
            <form wire:submit="activate" class="space-y-4">
                <x-ui.field for="activation_reason" :label="__('Skäl')" error="reason" :hint="__('Skriv inte personnummer eller andra personuppgifter än nödvändigt.')">
                    <textarea id="activation_reason" rows="2" class="{{ $input }} mt-1" wire:model="reason" maxlength="500" placeholder="{{ __('Till exempel: Kunden skrev under avtalet på papper den 3 mars.') }}"></textarea>
                </x-ui.field>
                <x-input-error for="agreement" :field="'agreement'" />
                <p class="text-sm text-gray-600">{{ __('När avtalet är aktivt går priserna inte att ändra. Fakturor, offerter och order till kunden som är daterade inom avtalets period får avtalets priser när en artikel väljs.') }}</p>
                <div class="flex items-center gap-4">
                    <x-button wire:loading.attr="disabled" wire:confirm="{{ __('Aktivera avtalet nu? Priserna låses och används på kundens dokument från startdatumet.') }}">{{ __('Aktivera avtalet') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelActivation">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($terminating)
        <x-ui.card :title="__('Säg upp avtalet')" :description="__('NONOX använder avtalets priser till och med den dag du väljer. Det här registrerar bara uppsägningen i NONOX; själva avtalet med kunden sägs upp enligt de villkor ni har kommit överens om.')">
            <form wire:submit="terminate" class="flex flex-wrap items-end gap-4">
                <x-ui.field for="terminate_on" :label="__('Sista dag avtalet gäller')" error="terminated_on">
                    <input id="terminate_on" type="date" class="{{ $input }} mt-1" wire:model="terminateOn" min="{{ $today->toDateString() }}">
                </x-ui.field>
                <x-button wire:loading.attr="disabled" wire:confirm="{{ __('Säga upp avtalet? Det går inte att ångra, men du kan göra ett nytt avtal senare.') }}">{{ __('Säg upp') }}</x-button>
                <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelTermination">{{ __('Avbryt') }}</button>
            </form>
        </x-ui.card>
    @endif

    @if($showItemForm)
        <x-ui.card :title="$editingItemId ? __('Ändra pris i avtalet') : __('Nytt pris i avtalet')">
            <form wire:submit="saveItem" class="grid gap-4 sm:grid-cols-6">
                <x-ui.field for="item_target" :label="__('Gäller')" error="target" class="sm:col-span-2">
                    <select id="item_target" class="{{ $input }} mt-1" wire:model.live="item.target">
                        <option value="article">{{ __('En artikel') }}</option>
                        <option value="all">{{ __('Alla artiklar') }}</option>
                    </select>
                </x-ui.field>

                @if($target === 'article')
                    <div class="sm:col-span-4">
                        <label for="agreement_article_search" class="block text-sm font-medium text-gray-700">{{ __('Artikel') }}</label>
                        @if($article)
                            <p class="mt-1 flex items-center gap-3 text-sm text-gray-900">
                                <span><span class="font-semibold tabular-nums">{{ $article->number }}</span> {{ $article->name }} <span class="text-gray-500">({{ __('grundpris :price', ['price' => $article->price_ore->format()]) }} / {{ $article->unit }})</span></span>
                                <button type="button" class="text-sm text-accent-700 underline" wire:click="clearArticle">{{ __('Byt artikel') }}</button>
                            </p>
                        @else
                            <input id="agreement_article_search" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.250ms="articleSearch" placeholder="{{ __('Sök på artikelnummer eller benämning') }}" autocomplete="off" aria-describedby="agreement_article_results">
                            <ul id="agreement_article_results" class="mt-1 divide-y divide-gray-100 rounded-lg ring-1 ring-stone-200" @if($articleMatches->isEmpty()) hidden @endif>
                                @foreach($articleMatches as $match)
                                    <li wire:key="match-{{ $match->id }}">
                                        <button type="button" class="flex w-full items-center justify-between gap-4 px-3 py-2 text-left text-sm hover:bg-stone-50 focus-visible:bg-stone-50" wire:click="chooseArticle({{ $match->id }})">
                                            <span><span class="font-semibold tabular-nums">{{ $match->number }}</span> {{ $match->name }}</span>
                                            <span class="tabular-nums text-gray-500">{{ $match->price_ore->format() }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <x-input-error for="article_id" :field="'article_id'" class="mt-2" />
                    </div>
                @endif

                @if($article)
                    <x-ui.field for="agreement_item_unit" :label="__('Enhet')" error="unit_id" class="sm:col-span-2" :hint="__('Tomt är artikelns egen försäljningsenhet.')">
                        <select id="agreement_item_unit" class="{{ $input }} mt-1" wire:model="item.unit_id">
                            <option value="">{{ __('Artikelns enhet (:unit)', ['unit' => $article->unit]) }}</option>
                            @foreach($unitChoices as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->code }} ({{ $unit->name }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="agreement_item_min" :label="__('Från antal')" error="min_quantity" class="sm:col-span-2" :hint="__('Tomt gäller från första enheten. Flera rader med olika antal blir en trappa.')">
                    <input id="agreement_item_min" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="item.min_quantity" placeholder="10">
                </x-ui.field>
                <x-ui.field for="agreement_item_mode" :label="__('Ange')" error="mode" class="sm:col-span-2">
                    <select id="agreement_item_mode" class="{{ $input }} mt-1" wire:model.live="item.mode">
                        @if($target === 'article')
                            <option value="price">{{ __('Fast pris i :currency', ['currency' => $agreement->currency]) }}</option>
                        @endif
                        <option value="discount">{{ __('Rabatt i procent') }}</option>
                    </select>
                </x-ui.field>
                @if($mode === 'price' && $target === 'article')
                    <x-ui.field for="agreement_item_price" :label="__('Pris exkl. moms')" error="price" class="sm:col-span-2">
                        <input id="agreement_item_price" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="item.price" placeholder="149,00">
                    </x-ui.field>
                @else
                    <x-ui.field for="agreement_item_discount" :label="__('Rabatt, procent')" error="discount" class="sm:col-span-2">
                        <input id="agreement_item_discount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="item.discount" placeholder="10">
                    </x-ui.field>
                    <x-ui.field for="agreement_item_list" :label="__('Rabatt på')" error="base_price_list_id" class="sm:col-span-4" :hint="__('Ordinarie pris är standardlistans pris för valutan, annars artikelns grundpris.')">
                        <select id="agreement_item_list" class="{{ $input }} mt-1" wire:model="item.base_price_list_id">
                            <option value="">{{ __('Ordinarie pris') }}</option>
                            @foreach($listChoices as $list)
                                <option value="{{ $list->id }}">{{ __('Prislistan :code · :name', ['code' => $list->code, 'name' => $list->name]) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif

                <div class="flex items-center gap-4 sm:col-span-6">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelItem">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Priser i avtalet')" :description="__('En artikels eget pris går före en rabatt på alla artiklar. Ett pris från ett visst antal gäller hela antalet på raden.')">
        @if($canChangeTerms && ! $showItemForm)
            <x-button type="button" wire:click="createItem">{{ __('Nytt pris') }}</x-button>
        @endif

        @if($items === [])
            <x-ui.empty-state class="mt-6" :title="__('Inga priser i avtalet ännu')">
                {{ __('Lägg in ett fast pris per artikel, eller en rabatt i procent på alla artiklar. Avtalet kan inte skickas eller aktiveras utan minst ett pris.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm" data-agreement-items>
                    <caption class="sr-only">{{ __('Priser i prisavtalet') }}</caption>
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Från antal') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Pris eller rabatt') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Grundpris') }}</th>
                            <th scope="col" class="py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($items as $row)
                            <tr wire:key="agreement-item-{{ $row->id }}">
                                <td class="py-3 pr-4">
                                    @if($row->applies_to_all)
                                        <span class="font-medium text-gray-900">{{ __('Alla artiklar') }}</span>
                                    @else
                                        <span class="font-semibold tabular-nums text-gray-900">{{ $row->article?->number }}</span> {{ $row->article?->name }}
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-right tabular-nums">{{ $row->min_quantity_milli === 0 ? '–' : App\Modules\Inventory\Domain\Quantity::format($row->min_quantity_milli) }} <span class="text-gray-500">{{ $row->unit?->code ?? ($row->applies_to_all ? '' : $row->article?->unit) }}</span></td>
                                <td class="py-3 pr-4 text-right font-medium tabular-nums text-gray-900">
                                    @if($row->price_minor !== null)
                                        {{ App\Support\Money\Money::ofOre($row->price_minor, $agreement->currency)->format() }}
                                    @else
                                        −{{ App\Domain\Pricing\PriceInput::basisPointsToPercent((int) $row->discount_bp) }} %
                                        <span class="block text-xs font-normal text-gray-500">{{ $row->basePriceList ? __('på :code', ['code' => $row->basePriceList->code]) : __('på ordinarie pris') }}</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-right tabular-nums text-gray-500">{{ $row->article?->price_ore->format() }}</td>
                                <td class="py-3 text-right whitespace-nowrap">
                                    @if($canChangeTerms)
                                        <button type="button" class="text-sm font-medium text-accent-700 hover:underline" wire:click="editItem({{ $row->id }})">{{ __('Ändra') }}</button>
                                        <button type="button" class="ms-3 text-sm font-medium text-gray-700 underline" wire:click="removeItem({{ $row->id }})" wire:confirm="{{ __('Ta bort priset ur utkastet?') }}">{{ __('Ta bort') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($showSigning)
        <livewire:signing.panel :price-agreement-id="$agreement->id" :key="'signing-'.$agreement->id.'-'.$status->value" />
    @endif
</div>
