@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-6">
    @if($printing !== null)
        <livewire:inventory.label-print kind="LOCATION" :items="$printing" :key="'label-print-'.md5((string) json_encode($printing))" />
    @endif

    @if($editing !== null)
        <x-ui.card :title="$editing ? __('Ändra lagerställe') : __('Nytt lagerställe')">
            <form wire:submit="save" class="grid gap-5 sm:grid-cols-6">
                @if($editing === 0)
                    <x-ui.field for="location-type" :label="__('Nivå')" error="form.type" class="sm:col-span-2">
                        <select id="location-type" wire:model.live="form.type" class="{{ $input }}">
                            @foreach($types as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    @if(($form['type'] ?? '') !== 'WAREHOUSE')
                        <x-ui.field for="location-parent" :label="__('Ligger i')" error="form.parent_id" class="sm:col-span-4">
                            <select id="location-parent" wire:model="form.parent_id" class="{{ $input }}">
                                <option value="">{{ __('Välj plats') }}</option>
                                @foreach($parents as $parent)
                                    <option value="{{ $parent->id }}">{{ $parent->full_code }} {{ $parent->name }} ({{ $parent->type->label() }})</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif
                @endif
                <x-ui.field for="location-code" :label="__('Kod')" error="form.code" class="sm:col-span-2" :hint="__('Kort, till exempel A, 03 eller 2. Hela koden byggs av platserna ovanför.')">
                    <input id="location-code" wire:model="form.code" maxlength="20" class="{{ $input }} uppercase" required autocomplete="off">
                </x-ui.field>
                <x-ui.field for="location-name" :label="__('Namn')" error="form.name" class="sm:col-span-4" :hint="__('Valfritt, till exempel Huvudlager eller Kylrum.')">
                    <input id="location-name" wire:model="form.name" maxlength="255" class="{{ $input }}">
                </x-ui.field>
                @if(($form['type'] ?? '') === 'WAREHOUSE')
                    <x-ui.field for="location-address" :label="__('Adress')" error="form.address_line1" class="sm:col-span-3">
                        <input id="location-address" wire:model="form.address_line1" class="{{ $input }}" autocomplete="street-address">
                    </x-ui.field>
                    <x-ui.field for="location-postal" :label="__('Postnummer')" error="form.postal_code" class="sm:col-span-1">
                        <input id="location-postal" wire:model="form.postal_code" maxlength="10" class="{{ $input }}" autocomplete="postal-code">
                    </x-ui.field>
                    <x-ui.field for="location-city" :label="__('Ort')" error="form.city" class="sm:col-span-2">
                        <input id="location-city" wire:model="form.city" class="{{ $input }}" autocomplete="address-level2">
                    </x-ui.field>
                @endif
                <div class="flex flex-wrap gap-3 sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($canManage && $editing === null)
            <x-slot:cta>
                <x-button type="button" wire:click="create">{{ __('Nytt lager') }}</x-button>
            </x-slot:cta>
        @endif

        <div class="mb-4 flex flex-wrap items-end gap-4">
            <label class="block grow sm:max-w-xs">
                <span class="text-sm font-medium text-gray-700">{{ __('Sök kod eller namn') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="show" class="{{ $input }}">
                    <option value="aktiva">{{ __('Aktiva') }}</option>
                    <option value="alla">{{ __('Alla, även inaktiva') }}</option>
                </select>
            </label>
        </div>

        @if($locations->isEmpty())
            <x-ui.empty-state :title="$search === '' ? __('Inga lagerställen ännu') : __('Inget lagerställe matchar sökningen')">
                {{ __('Börja med ett lager, till exempel butiken eller förrådet. Lägg sedan till zoner, hyllor och fack under det, så hittar plocklistan rätt.') }}
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-stone-200" aria-label="{{ __('Lagerställen') }}">
                @foreach($locations as $location)
                    <li wire:key="location-{{ $location->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3" style="padding-left: {{ $location->type->depth() * 1.25 }}rem">
                        <span class="min-w-0">
                            <span @class(['font-semibold tabular-nums', 'text-ink' => $location->is_active, 'text-gray-500 line-through' => ! $location->is_active])>{{ $location->full_code }}</span>
                            <span class="text-gray-700">{{ $location->name }}</span>
                            <span class="ml-1 text-xs text-gray-500">{{ $location->type->label() }}</span>
                            @if($location->city)<span class="block text-sm text-gray-500">{{ $location->address_line1 }}, {{ $location->postal_code }} {{ $location->city }}</span>@endif
                        </span>
                        <span class="flex flex-wrap items-center gap-3 text-sm">
                            <span class="tabular-nums text-gray-600">{{ App\Modules\Inventory\Domain\Quantity::format((int) $location->stock_milli) }} {{ __('i lager') }}</span>
                            @if($canPrint && $location->is_active)
                                <button type="button" wire:click="printLabels({{ $location->id }})" class="font-semibold text-accent-700 underline">{{ __('Etikett') }}</button>
                                @if($location->type->value !== 'BIN')
                                    <button type="button" wire:click="printLabels({{ $location->id }}, true)" class="font-semibold text-accent-700 underline">{{ __('Etiketter för allt under') }}</button>
                                @endif
                            @endif
                            @if($canManage)
                                @if($location->type->value !== 'BIN' && $location->is_active)
                                    <button type="button" wire:click="create({{ $location->id }})" class="font-semibold text-accent-700 underline">{{ __('Lägg till under') }}</button>
                                @endif
                                <button type="button" wire:click="edit({{ $location->id }})" class="font-semibold text-accent-700 underline">{{ __('Ändra') }}</button>
                                <button type="button" wire:click="toggle({{ $location->id }})" class="font-semibold text-gray-700 underline">{{ $location->is_active ? __('Inaktivera') : __('Aktivera') }}</button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
