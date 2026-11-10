@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $textLabels = [
        'k3_business_changes' => [__('Viktiga förändringar i verksamheten'), __('Förvaltningsberättelsen (K3 punkt 3.7).')],
        'k3_external_factors' => [__('Viktiga externa faktorer'), __('Till exempel marknad, priser eller regler som påverkat företaget.')],
        'k3_special_circumstances' => [__('Speciella omständigheter'), __('Omständigheter som särskilt berör företaget.')],
        'k3_ownership_changes' => [__('Väsentliga förändringar i ägarstrukturen'), __('Lämna tomt om ägarna inte har ändrats väsentligt.')],
        'k3_off_balance_arrangements' => [__('Arrangemang utanför balansräkningen'), __('Art och syfte med arrangemang som medför väsentliga risker eller fördelar (årsredovisningslagen 5 kap. 17 §).')],
        'k3_loans_to_executives' => [__('Lån till styrelse och verkställande direktör'), __('Belopp, villkor, ränta och säkerheter (årsredovisningslagen 5 kap. 18 §).')],
        'k3_parent_company' => [__('Moderföretag'), __('Namn, organisationsnummer och säte för moderföretaget i den minsta koncern som upprättar koncernredovisning (årsredovisningslagen 5 kap. 21 §).')],
        'k3_related_party_transactions' => [__('Transaktioner med närstående'), __('Bara för ett publikt aktiebolag (årsredovisningslagen 5 kap. 23 §).')],
        'k3_departures' => [__('Avvikelser från allmänna råd'), __('Vilken avvikelse och varför (årsredovisningslagen 2 kap. 3 §).')],
        'k3_goodwill_note' => [__('Goodwill'), __('Avskrivningstid och skälen för den (årsredovisningslagen 5 kap. 9 §).')],
    ];
    $answerLabels = [
        'group_commitments' => __('Åtaganden till förmån för koncern- eller intresseföretag'),
        'off_balance_arrangements' => __('Arrangemang som inte tas upp i balansräkningen'),
        'loans_to_executives' => __('Lån eller säkerheter för styrelse eller verkställande direktör'),
        'parent_company' => __('Företaget är dotterföretag i en koncern som upprättar koncernredovisning'),
        'material_error_corrected' => __('Ett väsentligt fel från tidigare år har rättats retroaktivt'),
        'no_employees' => __('Företaget har inte haft några anställda'),
    ];
@endphp

<x-ui.card :title="__('Uppgifter för K3')" :description="__('Det som bokföringen inte kan svara på. Svaren styr vilka noter som tas med; en not utan uppgifter tas inte med.')">
    <form wire:submit="saveK3" class="space-y-4">
        <fieldset class="space-y-3">
            <legend class="text-sm font-semibold text-gray-900">{{ __('Frågor') }}</legend>
            @foreach($k3Answers as $key)
                <x-ui.field for="k3_answer_{{ $key }}" :label="$answerLabels[$key]" error="k3_answers.{{ $key }}">
                    <select id="k3_answer_{{ $key }}" class="{{ $input }} mt-1" wire:model="k3Form.answers.{{ $key }}">
                        <option value="">{{ __('Inte besvarad') }}</option>
                        <option value="yes">{{ __('Ja') }}</option>
                        <option value="no">{{ __('Nej') }}</option>
                    </select>
                </x-ui.field>
            @endforeach
        </fieldset>

        <x-ui.field for="k3_fte" :label="__('Medelantal anställda, heltidstjänster')" error="average_employees_fte" :hint="__('Beräknat enligt BFNAR 2006:11, till exempel 3,5. Det står i noten om medelantalet anställda.')"><input id="k3_fte" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="k3Form.average_employees_fte"></x-ui.field>

        <fieldset class="space-y-3 border-t border-stone-200 pt-4">
            <legend class="text-sm font-semibold text-gray-900">{{ __('Ägare med mer än tio procent') }}</legend>
            <p class="text-xs text-gray-500">{{ __('Förvaltningsberättelsen ska ange ägare med mer än tio procent av andelarna eller rösterna (K3 punkt 3.7 e). För en fysisk person anges bara namnet; ett personnummer sparas aldrig.') }}</p>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" class="rounded border-stone-300 text-accent-600 focus:ring-accent-500" wire:model.live="ownersAnswered"> {{ __('Jag har angett ägarna (ingen rad betyder att ingen äger mer än tio procent)') }}</label>
            @foreach($owners as $index => $owner)
                <div wire:key="k3-owner-{{ $index }}" class="grid gap-3 rounded-2xl bg-stone-50 p-3 ring-1 ring-stone-200 sm:grid-cols-2">
                    <x-ui.field for="k3_owner_name_{{ $index }}" :label="__('Namn')" error="k3_major_owners.{{ $index }}.name"><input id="k3_owner_name_{{ $index }}" type="text" class="{{ $input }} mt-1" wire:model="owners.{{ $index }}.name"></x-ui.field>
                    <x-ui.field for="k3_owner_type_{{ $index }}" :label="__('Slag av ägare')" error="k3_major_owners.{{ $index }}">
                        <select id="k3_owner_type_{{ $index }}" class="{{ $input }} mt-1" wire:model.live="owners.{{ $index }}.owner_type">
                            <option value="legal">{{ __('Företag eller annan juridisk person') }}</option>
                            <option value="natural">{{ __('Fysisk person') }}</option>
                        </select>
                    </x-ui.field>
                    @if($owner['owner_type'] === 'legal')
                        <x-ui.field for="k3_owner_org_{{ $index }}" :label="__('Organisationsnummer (valfritt)')" error="k3_major_owners.{{ $index }}.org_number"><input id="k3_owner_org_{{ $index }}" type="text" class="{{ $input }} mt-1" wire:model="owners.{{ $index }}.org_number"></x-ui.field>
                    @endif
                    <x-ui.field for="k3_owner_votes_{{ $index }}" :label="__('Andel av rösterna, %')" error="k3_major_owners.{{ $index }}.votes_bp"><input id="k3_owner_votes_{{ $index }}" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="owners.{{ $index }}.votes"></x-ui.field>
                    <x-ui.field for="k3_owner_capital_{{ $index }}" :label="__('Andel av kapitalet, %')" error="k3_major_owners.{{ $index }}.capital_bp"><input id="k3_owner_capital_{{ $index }}" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="owners.{{ $index }}.capital"></x-ui.field>
                    <div class="sm:col-span-2"><button type="button" wire:click="removeOwner({{ $index }})" class="text-sm font-medium text-red-700 hover:underline">{{ __('Ta bort ägaren') }}</button></div>
                </div>
            @endforeach
            <button type="button" wire:click="addOwner" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Lägg till ägare') }}</button>
        </fieldset>

        <fieldset class="space-y-4 border-t border-stone-200 pt-4">
            <legend class="text-sm font-semibold text-gray-900">{{ __('Texter till förvaltningsberättelse och noter') }}</legend>
            @foreach($k3Texts as $field)
                <x-ui.field for="k3_{{ $field }}" :label="$textLabels[$field][0]" error="{{ $field }}" :hint="$textLabels[$field][1]"><textarea id="k3_{{ $field }}" rows="2" class="{{ $input }} mt-1" wire:model="k3Form.{{ $field }}"></textarea></x-ui.field>
            @endforeach
            <x-ui.field for="k3_long_term" :label="__('Skulder som förfaller efter mer än fem år, kr')" error="k3_long_term_liabilities" :hint="__('Högst de långfristiga skulderna i bokföringen (konto 2300–2399).')"><input id="k3_long_term" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="k3Form.k3_long_term_liabilities"></x-ui.field>
        </fieldset>

        <fieldset class="space-y-3 border-t border-stone-200 pt-4">
            <legend class="text-sm font-semibold text-gray-900">{{ __('Poster av exceptionell storlek eller förekomst') }}</legend>
            @foreach($exceptional as $index => $item)
                <div wire:key="k3-exceptional-{{ $index }}" class="grid gap-3 sm:grid-cols-[1fr_10rem_auto] sm:items-end">
                    <x-ui.field for="k3_exc_desc_{{ $index }}" :label="__('Beskrivning')" error="k3_exceptional_items.{{ $index }}.description"><input id="k3_exc_desc_{{ $index }}" type="text" class="{{ $input }} mt-1" wire:model="exceptional.{{ $index }}.description"></x-ui.field>
                    <x-ui.field for="k3_exc_amount_{{ $index }}" :label="__('Belopp, kr')" error="k3_exceptional_items.{{ $index }}.amount"><input id="k3_exc_amount_{{ $index }}" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="exceptional.{{ $index }}.amount"></x-ui.field>
                    <button type="button" wire:click="removeExceptional({{ $index }})" class="pb-2 text-sm font-medium text-red-700 hover:underline">{{ __('Ta bort') }}</button>
                </div>
            @endforeach
            <button type="button" wire:click="addExceptional" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Lägg till post') }}</button>
        </fieldset>

        <x-button>{{ __('Spara uppgifterna för K3') }}</x-button>
    </form>
</x-ui.card>

@if($k3Notes !== [])
    <x-ui.card data-k3-notes :title="__('Noter')" :description="__('Noterna tas fram ur bokföringen och dina svar. Du kan skriva en not med egna ord; siffrorna och om noten tas med styrs fortfarande av bokföringen.')">
        <ul class="divide-y divide-stone-200" aria-label="{{ __('Noter i årsredovisningen') }}">
            @foreach($k3Notes as $note)
                <li wire:key="k3-note-{{ $note->key }}" class="py-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-medium text-gray-900">{{ __('Not :number', ['number' => $note->number]) }} · {{ $note->heading }}</p>
                        <div class="flex flex-wrap items-center gap-2">
                            @if($note->overridden())
                                <x-ui.badge tone="accent">{{ __('Egen formulering') }}</x-ui.badge>
                            @endif
                            @if($note->stale)
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">{{ __('Inaktuell') }}</span>
                            @endif
                            @if($note->openQuestions() !== [])
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-900 ring-1 ring-amber-200">{{ __('Frågor: :count', ['count' => count($note->openQuestions())]) }}</span>
                            @endif
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ implode(', ', $note->rules) }}</p>
                    @if($note->stale)
                        <p class="mt-1 text-xs text-amber-900">{{ __('Bokföringen har ändrats sedan du skrev texten. Läs igenom den igen och spara den på nytt.') }}</p>
                    @endif
                    @if($editingNote === $note->key)
                        <form wire:submit="saveNote" class="mt-3 space-y-3">
                            <x-ui.field for="k3_note_text" :label="__('Text')" error="text" :hint="__('En tom rad mellan styckena.')"><textarea id="k3_note_text" rows="6" class="{{ $input }} mt-1" wire:model="noteText"></textarea></x-ui.field>
                            <x-ui.field for="k3_note_reason" :label="__('Skäl')" error="reason" :hint="__('Varför du skriver noten med egna ord. Skälet syns bara här, inte i årsredovisningen.')"><input id="k3_note_reason" type="text" class="{{ $input }} mt-1" wire:model="noteReason"></x-ui.field>
                            <div class="flex flex-wrap items-center gap-4">
                                <x-button>{{ __('Spara texten') }}</x-button>
                                <button type="button" wire:click="cancelNote" class="text-sm font-medium text-gray-700 hover:underline">{{ __('Avbryt') }}</button>
                            </div>
                        </form>
                    @else
                        <div class="mt-2 flex flex-wrap gap-4">
                            <button type="button" wire:click="editNote('{{ $note->key }}')" class="text-sm font-medium text-accent-700 hover:underline">{{ $note->overridden() ? __('Ändra egen formulering') : __('Skriv med egna ord') }}</button>
                            @if($note->overridden())
                                <button type="button" wire:click="removeNoteOverride('{{ $note->key }}')" class="text-sm font-medium text-red-700 hover:underline">{{ __('Använd NONOX text') }}</button>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-ui.card>
@endif
