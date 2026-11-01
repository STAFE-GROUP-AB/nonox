<x-ui.card :title="__('Anställningens slut')">
    @if($endedOn)
        <p class="text-sm text-gray-900">{{ __('Anställningen slutade :date.', ['date' => $endedOn->toDateString()]) }}</p>
    @else
        <p class="text-sm text-gray-600">{{ __('Lönekörningen för den sista månaden betalar lön till och med den sista dagen och semesterersättning för semester som är kvar och intjänad. Sedan är den anställde inte med i lönekörningarna.') }}</p>
        <form wire:submit="endEmployment" class="mt-4 grid gap-4 sm:grid-cols-3">
            <x-ui.field for="ending_date" :label="__('Sista anställningsdag')" error="end_date"><input id="ending_date" type="date" class="block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs mt-1" wire:model="ending.end_date"></x-ui.field>
            <x-ui.field for="ending_reason" :label="__('Orsak (frivilligt)')" error="reason" class="sm:col-span-2"><input id="ending_reason" type="text" maxlength="255" class="block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs mt-1" wire:model="ending.reason" placeholder="{{ __('Till exempel Egen uppsägning') }}"></x-ui.field>
            <div class="sm:col-span-3">
                @if($confirmingEnd)
                    <p class="text-sm font-medium text-gray-900">{{ __('Den sista lönen räknas med semesterersättning och den anställde tas bort ur kommande lönekörningar. Det går inte att ångra här.') }}</p>
                    <div class="mt-3 flex items-center gap-4">
                        <x-button wire:loading.attr="disabled">{{ __('Ja, avsluta anställningen') }}</x-button>
                        <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('confirmingEnd', false)">{{ __('Avbryt') }}</button>
                    </div>
                @else
                    <x-button type="button" wire:click="$set('confirmingEnd', true)">{{ __('Avsluta anställningen') }}</x-button>
                @endif
            </div>
        </form>
    @endif
    <p class="mt-4 text-sm"><x-ui.link :href="route('payroll.employees.certificate', $editingId)">{{ __('Hämta underlag för arbetsgivarintyg (CSV)') }}</x-ui.link></p>
    <p class="mt-2 text-sm"><button type="button" class="text-accent-700 underline" wire:click="erase" wire:confirm="{{ __('Radera uppgifterna enligt GDPR? Har personen aldrig fått lön raderas allt. Annars tas kontakt- och bankuppgifter bort nu, och namn och personnummer finns kvar för lönebesked och AGI tills lagringstiden enligt bokföringslagen har gått ut.') }}">{{ __('Radera enligt GDPR') }}</button></p>
</x-ui.card>
