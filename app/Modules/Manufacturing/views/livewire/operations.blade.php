@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($money = fn (int $ore) => App\Support\Money\Money::ofOre($ore))
<div class="space-y-6">
    @if($editing)
        <x-ui.card :title="$editingId ? __('Ändra arbetssteg') : __('Nytt arbetssteg')" :description="__('Timkostnaden är företagets egen siffra för en timme i steget: lön med arbetsgivaravgifter och semester, och gärna maskinen. Den gäller nästa kalkyl; sparade offerter ändras inte.')">
            <form wire:submit="save" class="grid gap-5 sm:grid-cols-6">
                <x-ui.field for="operation-name" :label="__('Namn')" error="form.name" class="sm:col-span-2">
                    <input id="operation-name" wire:model="form.name" maxlength="100" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="operation-cost" :label="__('Timkostnad (kr)')" error="form.hourly_cost" class="sm:col-span-2">
                    <input id="operation-cost" wire:model="form.hourly_cost" inputmode="decimal" class="{{ $input }} text-right tabular-nums" placeholder="450,00" required>
                </x-ui.field>
                <x-ui.field for="operation-capacity" :label="__('Kapacitet, timmar per dag')" error="form.capacity_hours" class="sm:col-span-2" :hint="__('Frivilligt. Bemannade timmar per arbetsdag, till exempel 16 för två personer.')">
                    <input id="operation-capacity" wire:model="form.capacity_hours" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara arbetssteget') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($canManage && ! $editing)
            <x-slot:cta>
                <x-button type="button" wire:click="create">{{ __('Nytt arbetssteg') }}</x-button>
            </x-slot:cta>
        @endif

        <label class="mb-4 flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model.live="showArchived" class="rounded-sm border-stone-300 text-ink focus:ring-accent-500">
            {{ __('Visa arkiverade') }}
        </label>

        @if($operations->isEmpty())
            <x-ui.empty-state :title="__('Inga arbetssteg ännu')">
                {{ __('Lägg upp stegen i verkstaden med vad en timme kostar, till exempel Kapning, Hyvling, Montering, Slipning och Lackering. Sedan anger stycklistorna hur många minuter varje steg tar.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Arbetssteg') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Timkostnad') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Kapacitet per dag') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Används i') }}</th>
                            @if($canManage)
                                <th class="py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($operations as $operation)
                            <tr wire:key="operation-{{ $operation->id }}">
                                <td class="py-2 pr-4">
                                    <span class="font-semibold text-ink">{{ $operation->name }}</span>
                                    @if($operation->archived_at)<x-ui.badge>{{ __('Arkiverat') }}</x-ui.badge>@endif
                                </td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($operation->hourly_cost_ore)" /></td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $operation->daily_capacity_minutes === null ? '–' : App\Modules\Manufacturing\Livewire\OperationsPage::duration($operation->daily_capacity_minutes) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ trans_choice(':count stycklista|:count stycklistor', (int) $operation->used_in, ['count' => (int) $operation->used_in]) }}</td>
                                @if($canManage)
                                    <td class="py-2 text-right whitespace-nowrap">
                                        <button type="button" wire:click="edit({{ $operation->id }})" class="text-sm font-semibold text-accent-700 underline">{{ __('Ändra') }}</button>
                                        @if($operation->archived_at)
                                            <button type="button" wire:click="archive({{ $operation->id }}, false)" class="ml-3 text-sm font-semibold text-gray-700 underline">{{ __('Använd igen') }}</button>
                                        @else
                                            <button type="button" wire:click="archive({{ $operation->id }}, true)" wire:confirm="{{ __('Arkivera arbetssteget? Stycklistor som redan använder det behåller det, men nya rader kan inte välja det.') }}" class="ml-3 text-sm font-semibold text-gray-700 underline">{{ __('Arkivera') }}</button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
