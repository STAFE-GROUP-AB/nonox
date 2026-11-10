@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
@endphp
<div class="space-y-6">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Välj räkenskapsår') }}">
        @foreach($years as $option)
            <button type="button" wire:key="year-{{ $option->id }}" wire:click="$set('year', {{ $option->id }})" @class([
                'rounded-full px-4 py-2 text-sm font-semibold transition',
                'bg-ink text-white' => $option->id === $fiscalYear->id,
                'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => $option->id !== $fiscalYear->id,
            ]) @if($option->id === $fiscalYear->id) aria-current="true" @endif>{{ $option->label() }}</button>
        @endforeach
    </nav>

    @if(! $supported && $legalFormSupported)
        <x-ui.empty-state :title="__('Inte för det här regelverket')">
            <span class="block">{{ $refusal }}</span>
            <span class="mt-2 block">{{ __('Bokföringen, bokslutet och inkomstdeklarationen fungerar som vanligt. Årsredovisningen upprättar du i ett program som stöder regelverket, eller med hjälp av en redovisningskonsult: ladda ner årets SIE-fil under Räkenskapsår och läs in den där.') }}</span>
            <span class="mt-2 block">{{ __('Har företaget valt regelverk av misstag, och uppfyller det villkoren för K2, kan en ägare eller administratör ändra regelverket under Inställningar för företaget.') }}</span>
        </x-ui.empty-state>
    @elseif(! $supported)
        <x-ui.empty-state :title="__('Inte för den här företagsformen')">{{ __('Årsredovisningen görs här för aktiebolag och ekonomiska föreningar. Enskilda firmor upprättar ingen årsredovisning utan ett förenklat årsbokslut.') }}</x-ui.empty-state>
    @else
        @if($k3)
            @include('livewire.accounting.partials.k3-annual-report-status')
        @endif
        <div class="grid gap-6 xl:grid-cols-[minmax(0,26rem)_1fr]">
            <div class="space-y-6">
            <x-ui.card :title="__('Texter och underskrifter')">
                <form wire:submit="save" class="space-y-4">
                    <x-ui.field for="ar_business" :label="__('Allmänt om verksamheten')" error="business_description" :hint="__('Vad företaget gör och var det har sitt säte.')"><textarea id="ar_business" rows="4" class="{{ $input }} mt-1" wire:model="form.business_description"></textarea></x-ui.field>
                    <x-ui.field for="ar_events" :label="__('Väsentliga händelser under året')" error="significant_events" :hint="__('Lämna tomt om inget särskilt har hänt.')"><textarea id="ar_events" rows="3" class="{{ $input }} mt-1" wire:model="form.significant_events"></textarea></x-ui.field>
                    @if($netSalesVariations !== [] || filled($form['net_sales_comment'] ?? ''))
                        @foreach($netSalesVariations as $variation)
                            <p class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ __('Nettoomsättningen har förändrats med mer än 30 procent mellan :from och :to. K2 kräver att du kommenterar det i förvaltningsberättelsen.', ['from' => $variation['from'], 'to' => $variation['to']]) }}</p>
                        @endforeach
                        <x-ui.field for="ar_net_sales_comment" :label="__('Kommentar till nettoomsättningen')" error="net_sales_comment" :hint="__('Förklara varför omsättningen har ändrats, till exempel en ny kund eller ett förlorat avtal.')"><textarea id="ar_net_sales_comment" rows="3" class="{{ $input }} mt-1" wire:model="form.net_sales_comment"></textarea></x-ui.field>
                    @endif
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field for="ar_dividend" :label="__('Föreslagen utdelning, kr')" error="dividend" :hint="__('Högst det fria egna kapitalet, :free. Utdelningen ska också vara försvarlig med hänsyn till bolagets behov av eget kapital och likviditet (försiktighetsregeln) – det bedömer styrelsen.', ['free' => \App\Support\Money\Money::ofOre(max($freeEquity, 0))->format()])"><input id="ar_dividend" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.dividend" placeholder="0"></x-ui.field>
                        <x-ui.field for="ar_employees" :label="__('Medelantal anställda')" error="average_employees"><input id="ar_employees" type="number" min="0" class="{{ $input }} mt-1" wire:model="form.average_employees"></x-ui.field>
                        <x-ui.field for="ar_place" :label="__('Ort')" error="signing_place"><input id="ar_place" type="text" class="{{ $input }} mt-1" wire:model="form.signing_place"></x-ui.field>
                        <x-ui.field for="ar_date" :label="__('Datum för underskrift')" error="signing_date"><input id="ar_date" type="date" class="{{ $input }} mt-1" wire:model="form.signing_date"></x-ui.field>
                    </div>
                    <x-ui.field for="ar_signatories" :label="__('Underskrifter')" error="signatories" :hint="__('En per rad: namn, roll. Till exempel Anna Andersson, Styrelseledamot.')"><textarea id="ar_signatories" rows="3" class="{{ $input }} mt-1" wire:model="form.signatories"></textarea></x-ui.field>
                    <fieldset class="space-y-4 border-t border-stone-200 pt-4">
                        <legend class="text-sm font-semibold text-gray-900">{{ __('Noter som du fyller i') }}</legend>
                        <p class="text-xs text-gray-500">{{ __('Fyll bara i det som finns. En not utan uppgifter tas inte med.') }}</p>
                        @if($hasBankLoans)
                            <p class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ __('Företaget har lån hos kreditinstitut. Har banken en företagsinteckning eller någon annan säkerhet ska den stå under Ställda säkerheter.') }}</p>
                        @endif
                        <div class="space-y-3">
                            <p class="text-sm font-medium text-gray-900">{{ __('Ställda säkerheter, kr') }}</p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                @foreach($pledgedKinds as $kind)
                                    <x-ui.field for="ar_pledged_{{ $kind->value }}" :label="$kind->label()" error="pledged.{{ $kind->value }}" :hint="$kind->amountHint()"><input id="ar_pledged_{{ $kind->value }}" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.pledged.{{ $kind->value }}"></x-ui.field>
                                @endforeach
                            </div>
                            <x-ui.field for="ar_pledged_other_text" :label="__('Vad är de övriga säkerheterna?')" error="pledged_other_text"><input id="ar_pledged_other_text" type="text" class="{{ $input }} mt-1" wire:model="form.pledged_other_text"></x-ui.field>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.field for="ar_contingent" :label="__('Eventualförpliktelser, kr')" error="contingent_liabilities" :hint="__('Summan av till exempel borgen och garantier som inte står i balansräkningen.')"><input id="ar_contingent" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.contingent_liabilities"></x-ui.field>
                            <x-ui.field for="ar_contingent_text" :label="__('Vad gäller de?')" error="contingent_liabilities_text"><input id="ar_contingent_text" type="text" class="{{ $input }} mt-1" wire:model="form.contingent_liabilities_text"></x-ui.field>
                        </div>
                        <x-ui.field for="ar_group" :label="__('Åtaganden för koncern- och intresseföretag')" error="group_commitments" :hint="__('Säkerheter, garantier och andra åtaganden till förmån för koncernföretag, intresseföretag eller gemensamt styrda företag.')"><textarea id="ar_group" rows="2" class="{{ $input }} mt-1" wire:model="form.group_commitments"></textarea></x-ui.field>
                        <x-ui.field for="ar_events_after" :label="__('Väsentliga händelser efter räkenskapsårets slut')" error="events_after" :hint="__('Vad som hänt och vilken ekonomisk effekt det får.')"><textarea id="ar_events_after" rows="3" class="{{ $input }} mt-1" wire:model="form.events_after"></textarea></x-ui.field>
                        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" class="rounded border-stone-300 text-accent-600 focus:ring-accent-500" wire:model="form.events_effect_unknown"> {{ __('Den ekonomiska effekten går inte att uppskatta') }}</label>
                    </fieldset>
                    <fieldset class="space-y-4 border-t border-stone-200 pt-4">
                        <legend class="text-sm font-semibold text-gray-900">{{ __('Fastställelseintyg') }}</legend>
                        <p class="text-xs text-gray-500">{{ __('Fylls i efter stämman. Intyget kommer sist i PDF:en, på kopian som skickas till Bolagsverket (årsredovisningslagen 8 kap. 3 §).') }}</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.field for="ar_adoption_date" :label="__('Fastställelsedag')" error="adoption_date" :hint="__('Dagen då stämman fastställde resultat- och balansräkningen.')"><input id="ar_adoption_date" type="date" class="{{ $input }} mt-1" wire:model="form.adoption_date"></x-ui.field>
                            <x-ui.field for="ar_certifier_name" :label="__('Den som intygar')" error="certifier_name"><input id="ar_certifier_name" type="text" class="{{ $input }} mt-1" wire:model="form.certifier_name"></x-ui.field>
                            <x-ui.field for="ar_certifier_role" :label="__('Roll')" error="certifier_role" :hint="__('Styrelseledamot eller verkställande direktör.')"><input id="ar_certifier_role" type="text" class="{{ $input }} mt-1" wire:model="form.certifier_role"></x-ui.field>
                        </div>
                        <x-ui.field for="ar_adoption_decision" :label="__('Stämmans beslut om vinsten eller förlusten')" error="adoption_decision" :hint="__('Lämna tomt för att använda den bokförda resultatdispositionen.')"><textarea id="ar_adoption_decision" rows="3" class="{{ $input }} mt-1" wire:model="form.adoption_decision"></textarea></x-ui.field>
                    </fieldset>
                    <div class="flex flex-wrap items-center gap-4">
                        <x-button>{{ __('Spara') }}</x-button>
                        @if(! $k3 || $k3Complete)
                            <a href="{{ route('accounting.annualReport.pdf', $fiscalYear) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Ladda ner PDF') }}</a>
                        @else
                            <span class="text-sm text-gray-600">{{ __('PDF:en kan laddas ner när inget hindrar årsredovisningen och alla frågor är besvarade.') }}</span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500">{{ __('Gör bokslutet först så att årets resultat och skatt är bokförda. Revisionsberättelsen läggs till utanför systemet.') }}</p>
                    @if($ixbrl)
                        <div class="space-y-2 rounded-2xl bg-stone-50 p-4 ring-1 ring-stone-200">
                            <a href="{{ route('accounting.annualReport.ixbrl', $fiscalYear) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Ladda ner som iXBRL (Beta)') }}</a>
                            <p class="text-xs text-gray-500">{{ __('Samma årsredovisning som PDF:en, som en fil avsedd för digital inlämning till Bolagsverket. NONOX lämnar inte in filen åt dig än, och fastställelseintyget skrivs under hos Bolagsverket, inte i NONOX. Går en rad inte att märka upp får du veta vilken.') }}</p>
                        </div>
                    @endif
                </form>
            </x-ui.card>
            @if($k3)
                @include('livewire.accounting.partials.k3-annual-report-inputs')
            @endif
            </div>

            @if($k3 && $k3Refusals !== [])
                <x-ui.empty-state :title="__('Ingen förhandsvisning ännu')">{{ __('Årsredovisningen enligt K3 visas när det som står under "Det här hindrar årsredovisningen" är åtgärdat.') }}</x-ui.empty-state>
            @else
                <div class="overflow-hidden rounded-[28px] bg-white shadow-xs ring-1 ring-stone-200">
                    <iframe wire:key="preview-{{ $fiscalYear->id }}-{{ $version }}" src="{{ route('accounting.annualReport.show', $fiscalYear) }}" title="{{ __('Förhandsvisning av årsredovisningen') }}" class="h-280 w-full bg-white"></iframe>
                </div>
            @endif
        </div>
    @endif
</div>
