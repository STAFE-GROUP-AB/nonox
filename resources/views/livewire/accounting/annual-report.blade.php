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
                        <x-ui.field for="ar_dividend" :label="__('Föreslagen utdelning, kr')" error="dividend"><input id="ar_dividend" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.dividend" placeholder="0"></x-ui.field>
                        <x-ui.field for="ar_employees" :label="__('Medelantal anställda')" error="average_employees"><input id="ar_employees" type="number" min="0" class="{{ $input }} mt-1" wire:model="form.average_employees"></x-ui.field>
                        <x-ui.field for="ar_place" :label="__('Ort')" error="signing_place"><input id="ar_place" type="text" class="{{ $input }} mt-1" wire:model="form.signing_place"></x-ui.field>
                        <x-ui.field for="ar_date" :label="__('Datum för underskrift')" error="signing_date"><input id="ar_date" type="date" class="{{ $input }} mt-1" wire:model="form.signing_date"></x-ui.field>
                    </div>
                    <x-ui.field for="ar_signatories" :label="__('Underskrifter')" error="signatories" :hint="__('En per rad: namn, roll. Till exempel Anna Andersson, Styrelseledamot.')"><textarea id="ar_signatories" rows="3" class="{{ $input }} mt-1" wire:model="form.signatories"></textarea></x-ui.field>
                    <div class="flex flex-wrap items-center gap-4">
                        <x-button>{{ __('Spara') }}</x-button>
                        <a href="{{ route('accounting.annualReport.pdf', $fiscalYear) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Ladda ner PDF') }}</a>
                    </div>
                    <p class="text-xs text-gray-500">{{ __('Gör bokslutet först så att årets resultat och skatt är bokförda. Revisionsberättelse och fastställelseintyg läggs till utanför systemet.') }}</p>
                </form>
            </x-ui.card>

            <div class="overflow-hidden rounded-[28px] bg-white shadow-xs ring-1 ring-stone-200">
                <iframe wire:key="preview-{{ $fiscalYear->id }}-{{ $version }}" src="{{ route('accounting.annualReport.show', $fiscalYear) }}" title="{{ __('Förhandsvisning av årsredovisningen') }}" class="h-280 w-full bg-white"></iframe>
            </div>
        </div>
    @endif
</div>
