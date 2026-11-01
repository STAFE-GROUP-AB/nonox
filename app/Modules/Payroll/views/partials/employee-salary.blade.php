<x-ui.card :title="__('Lönerevision')" :description="__('En ny månadslön gäller från den första i en månad. Har en månad redan betalats ut med den gamla lönen läggs skillnaden till som retroaktiv lön i nästa lönekörning, skattad som engångsbelopp.')">
    @if($salaryHistory->isNotEmpty())
        <ul class="mb-5 divide-y divide-gray-100 text-sm">
            @foreach($salaryHistory as $change)
                <li wire:key="salary-change-{{ $change->id }}" class="flex flex-wrap gap-x-4 py-2">
                    <span class="w-28 tabular-nums text-gray-600">{{ $change->valid_from->toDateString() }}</span>
                    <x-ui.amount :money="$change->monthly_salary_ore" class="w-32 text-right" />
                    <span class="text-gray-500">{{ $change->reason }}</span>
                </li>
            @endforeach
        </ul>
    @endif
    <form wire:submit="changeSalary" class="grid gap-4 sm:grid-cols-3">
        <x-ui.field for="raise_valid_from" :label="__('Ny lön från')" error="valid_from"><input id="raise_valid_from" type="date" class="block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs mt-1" wire:model="raise.valid_from"></x-ui.field>
        <x-ui.field for="raise_salary" :label="__('Månadslön')" error="salary"><input id="raise_salary" type="text" inputmode="decimal" class="block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs mt-1 tabular-nums" wire:model="raise.salary" placeholder="{{ __('Till exempel 33 500') }}"></x-ui.field>
        <x-ui.field for="raise_reason" :label="__('Orsak (frivilligt)')" error="reason"><input id="raise_reason" type="text" maxlength="255" class="block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs mt-1" wire:model="raise.reason" placeholder="{{ __('Till exempel Lönerevision 2026') }}"></x-ui.field>
        <div class="sm:col-span-3"><x-button wire:loading.attr="disabled">{{ __('Spara ny lön') }}</x-button></div>
    </form>
</x-ui.card>
