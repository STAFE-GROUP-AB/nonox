<div class="space-y-6">
    <x-ui.card :title="__('Överlåt ägarskapet')" :description="__('Ge företaget till någon som redan finns i det. Den nya ägaren får göra allt, även arkivera företaget. Du blir kvar som administratör.')">
        @if($candidates->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Det finns ingen annan i företaget att överlåta till. Bjud först in personen under Uppgifter och personer.') }}</p>
        @else
            <form wire:submit="transfer" class="flex flex-wrap items-end gap-4">
                <x-ui.field for="new_owner" :label="__('Ny ägare')" class="min-w-64 flex-1">
                    <select id="new_owner" wire:model="newOwnerId" class="mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500">
                        <option value="">{{ __('Välj en person') }}</option>
                        @foreach($candidates as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $roleLabels[$candidate->getAttribute('company_role')] ?? $candidate->getAttribute('company_role') }})</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-danger-button type="submit" wire:loading.attr="disabled"
                    wire:confirm="{{ __('Vill du överlåta :company? Den du har valt blir ägare och kan göra allt i företaget, även ta bort dig. Du blir administratör och kan inte ångra överlåtelsen själv.', ['company' => $company->name]) }}">
                    {{ __('Överlåt ägarskapet') }}
                </x-danger-button>
            </form>
        @endif
    </x-ui.card>

    @if($company->isArchived())
        <x-ui.card :title="__('Företaget är arkiverat')" :description="__('Arkiverat :date. Allt finns kvar och kan läsas och exporteras under hela arkiveringstiden, men inget kan bokföras eller ändras.', ['date' => $company->archived_at?->setTimezone('Europe/Stockholm')->format('Y-m-d')])">
            <x-button type="button" wire:click="unarchive" wire:loading.attr="disabled"
                wire:confirm="{{ __('Vill du ta :company ur arkivet? Företaget kan då bokföras och ändras igen av alla med behörighet.', ['company' => $company->name]) }}">
                {{ __('Ta ur arkivet') }}
            </x-button>
        </x-ui.card>
    @else
        <x-ui.card :title="__('Arkivera företaget')" :description="__('Ett företag raderas aldrig. När verksamheten är avslutad arkiveras det: allt finns kvar och kan läsas och exporteras, men inget kan bokföras eller ändras. Räkenskapsinformationen ska sparas i sju år efter det kalenderår då räkenskapsåret slutade.')">
            <div class="space-y-4">
                <div>
                    <p class="text-sm font-medium text-gray-700">{{ __('Arkivpaket per räkenskapsår') }}</p>
                    @if($years === [])
                        <p class="mt-1 text-sm text-gray-600">{{ __('Företaget har inga räkenskapsår.') }}</p>
                    @else
                        <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white text-sm">
                            @foreach($years as $row)
                                <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="archive-year-{{ $row['year']->id }}">
                                    <span class="font-medium tabular-nums text-gray-900">{{ $row['year']->label() }}</span>
                                    @if($row['current'])
                                        <a href="{{ route('accounting.fiscalYears.archive', [$row['year']->id, $row['package']->id]) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ner arkivpaketet') }}</a>
                                    @elseif($row['busy'])
                                        <x-ui.badge>{{ __('Skapas') }}</x-ui.badge>
                                    @elseif($row['package'])
                                        <x-ui.badge tone="accent">{{ __('Inaktuellt, bokfört efter att paketet skapades') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge tone="accent">{{ __('Saknas') }}</x-ui.badge>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if(collect($years)->contains(fn (array $row): bool => ! $row['current'] && ! $row['busy']))
                            <x-secondary-button type="button" class="mt-3" wire:click="createMissingPackages" wire:loading.attr="disabled">{{ __('Skapa de arkivpaket som saknas') }}</x-secondary-button>
                        @endif
                    @endif
                </div>

                @if(collect($years)->contains(fn (array $row): bool => ! $row['current']))
                    <label class="flex items-start gap-2 text-sm text-gray-700">
                        <input type="checkbox" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="confirmedExport">
                        <span>{{ __('Jag har sparat räkenskapsinformationen för de år som saknar aktuellt arkivpaket på annat sätt och vill arkivera ändå.') }}</span>
                    </label>
                @endif

                <x-danger-button type="button" wire:click="archive" wire:loading.attr="disabled"
                    wire:confirm="{{ __('Vill du arkivera :company? Ingen kan längre bokföra, fakturera, betala, köra lön eller ändra inställningar i företaget. Allt finns kvar och kan läsas och exporteras, och du kan ta företaget ur arkivet senare.', ['company' => $company->name]) }}">
                    {{ __('Arkivera företaget') }}
                </x-danger-button>
            </div>
        </x-ui.card>
    @endif
</div>
