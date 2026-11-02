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

    @if(! $supported)
        <x-ui.empty-state :title="__('Inte för den här företagsformen')">{{ __('Årsredovisningen görs här för aktiebolag och ekonomiska föreningar. Enskilda firmor upprättar ingen årsredovisning utan ett förenklat årsbokslut.') }}</x-ui.empty-state>
    @else
        <div class="grid gap-6 xl:grid-cols-[minmax(0,26rem)_1fr]">
            <x-ui.card :title="__('Texter och underskrifter')">
                <form wire:submit="save" class="space-y-4">
                    <x-ui.field for="ar_business" :label="__('Allmänt om verksamheten')" error="business_description" :hint="__('Vad företaget gör och var det har sitt säte.')"><textarea id="ar_business" rows="4" class="{{ $input }} mt-1" wire:model="form.business_description"></textarea></x-ui.field>
                    <x-ui.field for="ar_events" :label="__('Väsentliga händelser under året')" error="significant_events" :hint="__('Lämna tomt om inget särskilt har hänt.')"><textarea id="ar_events" rows="3" class="{{ $input }} mt-1" wire:model="form.significant_events"></textarea></x-ui.field>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field for="ar_dividend" :label="__('Föreslagen utdelning, kr')" error="dividend" :hint="__('Högst det fria egna kapitalet, :free. Utdelningen ska också vara försvarlig med hänsyn till bolagets behov av eget kapital och likviditet (försiktighetsregeln) – det bedömer styrelsen.', ['free' => \App\Support\Money\Money::ofOre(max($freeEquity, 0))->format()])"><input id="ar_dividend" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.dividend" placeholder="0"></x-ui.field>
                        <x-ui.field for="ar_employees" :label="__('Medelantal anställda')" error="average_employees"><input id="ar_employees" type="number" min="0" class="{{ $input }} mt-1" wire:model="form.average_employees"></x-ui.field>
                        <x-ui.field for="ar_place" :label="__('Ort')" error="signing_place"><input id="ar_place" type="text" class="{{ $input }} mt-1" wire:model="form.signing_place"></x-ui.field>
                        <x-ui.field for="ar_date" :label="__('Datum för underskrift')" error="signing_date"><input id="ar_date" type="date" class="{{ $input }} mt-1" wire:model="form.signing_date"></x-ui.field>
                    </div>
                    <x-ui.field for="ar_signatories" :label="__('Underskrifter')" error="signatories" :hint="__('En per rad: namn, roll. Till exempel Anna Andersson, Styrelseledamot.')"><textarea id="ar_signatories" rows="3" class="{{ $input }} mt-1" wire:model="form.signatories"></textarea></x-ui.field>
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
                        <a href="{{ route('accounting.annualReport.pdf', $fiscalYear) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Ladda ner PDF') }}</a>
                    </div>
                    <p class="text-xs text-gray-500">{{ __('Gör bokslutet först så att årets resultat och skatt är bokförda. Revisionsberättelsen läggs till utanför systemet.') }}</p>
                </form>
            </x-ui.card>

            <div class="overflow-hidden rounded-[28px] bg-white shadow-xs ring-1 ring-stone-200">
                <iframe wire:key="preview-{{ $fiscalYear->id }}-{{ $version }}" src="{{ route('accounting.annualReport.show', $fiscalYear) }}" title="{{ __('Förhandsvisning av årsredovisningen') }}" class="h-280 w-full bg-white"></iframe>
            </div>
        </div>
    @endif
</div>
