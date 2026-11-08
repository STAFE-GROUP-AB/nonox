@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($qty = fn (int $milli) => App\Modules\Inventory\Domain\Quantity::format($milli))
<div class="space-y-6">
    @if($canRegister)
        <x-ui.card :title="__('Registrera svinn')" :description="__('Registrera bara det som har slängts eller ska slängas nu. Varor som väntar på att slängas registreras när de slängs.')">
            <form wire:submit="register" class="grid gap-5 sm:grid-cols-6">
                <x-ui.field for="waste-kind" :label="__('Vad slängs')" error="form.kind" class="sm:col-span-2">
                    <select id="waste-kind" wire:model.live="form.kind" class="{{ $input }}">
                        <option value="INGREDIENT">{{ __('En råvara') }}</option>
                        <option value="DISH">{{ __('En rätt eller ett grundrecept') }}</option>
                    </select>
                </x-ui.field>
                <x-ui.field for="waste-article" :label="($form['kind'] ?? '') === 'DISH' ? __('Rätt') : __('Råvara')" error="form.article_id" class="sm:col-span-4" :hint="($form['kind'] ?? '') === 'DISH' ? __('Ingredienserna dras från lagret enligt receptet.') : null">
                    <select id="waste-article" wire:model="form.article_id" class="{{ $input }}" required>
                        <option value="">{{ __('Välj') }}</option>
                        @foreach(($form['kind'] ?? '') === 'DISH' ? $dishes : $ingredients as $option)
                            <option value="{{ $option->id }}">{{ $option->name }} ({{ ($form['kind'] ?? '') === 'DISH' ? __('portioner eller enhet') : $option->unit }})</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="waste-quantity" :label="__('Mängd')" error="form.quantity" class="sm:col-span-2">
                    <input id="waste-quantity" wire:model="form.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums" required>
                </x-ui.field>
                <x-ui.field for="waste-reason" :label="__('Orsak')" error="form.reason" class="sm:col-span-2">
                    <select id="waste-reason" wire:model.live="form.reason" class="{{ $input }}" required>
                        <option value="">{{ __('Välj orsak') }}</option>
                        @foreach($reasons as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="waste-date" :label="__('Datum')" error="form.date" class="sm:col-span-2">
                    <input id="waste-date" type="date" wire:model="form.date" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="waste-location" :label="__('Lagerställe')" error="form.location_id" class="sm:col-span-3">
                    <select id="waste-location" wire:model="form.location_id" class="{{ $input }}">
                        <option value="">{{ __('Kökets lagerställe') }}</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if(($form['reason'] ?? '') === 'SKANKT')
                    <x-ui.field for="waste-recipient" :label="__('Mottagande organisation')" error="form.recipient" class="sm:col-span-3" :hint="__('Skatteverket anser att skänkt mat nära bäst före-datum normalt inte har något beskattningsunderlag. NONOX bokar ingen moms för skänkt mat.')">
                        <input id="waste-recipient" wire:model="form.recipient" maxlength="120" class="{{ $input }}">
                    </x-ui.field>
                @endif
                <x-ui.field for="waste-note" :label="__('Anteckning')" error="form.note" class="sm:col-span-6">
                    <input id="waste-note" wire:model="form.note" maxlength="255" class="{{ $input }}" placeholder="{{ __('Valfritt, krävs när orsaken är Övrigt') }}">
                </x-ui.field>
                <div class="sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Registrera svinnet') }}</x-button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card :title="__('Tallrikssvinn')" :description="__('Mat som gästerna lämnat. Den är redan såld, så inget dras från lagret; vikten sparas som en mätning.')">
            <form wire:submit="weigh" class="grid gap-5 sm:grid-cols-6">
                <x-ui.field for="plate-kg" :label="__('Vikt i kg')" error="plate.kg" class="sm:col-span-2">
                    <input id="plate-kg" wire:model="plate.kg" inputmode="decimal" class="{{ $input }} text-right tabular-nums" required>
                </x-ui.field>
                <x-ui.field for="plate-date" :label="__('Datum')" error="plate.date" class="sm:col-span-2">
                    <input id="plate-date" type="date" wire:model="plate.date" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="plate-note" :label="__('Anteckning')" error="plate.note" class="sm:col-span-2">
                    <input id="plate-note" wire:model="plate.note" maxlength="255" class="{{ $input }}">
                </x-ui.field>
                <div class="sm:col-span-6">
                    <x-secondary-button type="submit" wire:loading.attr="disabled">{{ __('Spara vikten') }}</x-secondary-button>
                </div>
            </form>
            @if($measurements->isNotEmpty())
                <ul class="mt-4 divide-y divide-stone-200 text-sm">
                    @foreach($measurements as $measurement)
                        <li class="flex justify-between py-2" wire:key="plate-{{ $measurement->id }}"><span>{{ $measurement->measured_on->toDateString() }} {{ $measurement->note }}</span><span class="tabular-nums">{{ $qty($measurement->kg_milli) }} kg</span></li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Svinnloggen')" :description="__('Loggen kan inte ändras. En felaktig rad rättas med en ny rad som lägger tillbaka varorna i lagret.')">
        @if($entries->isEmpty())
            <x-ui.empty-state :title="__('Inget svinn registrerat')">
                {{ __('Registrera det som slängs här eller med mobilen. Svinnet visas sedan i avvikelserapporten, sorterat på orsak.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Datum') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Vad') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Mängd') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Orsak') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Registrerad av') }}</th>
                            <th class="py-2 pr-4"><span class="sr-only">{{ __('Åtgärd') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($entries as $entry)
                            <tr wire:key="waste-{{ $entry->id }}">
                                <td class="py-2 pr-4 tabular-nums">{{ $entry->registered_on->toDateString() }}</td>
                                <td class="py-2 pr-4">{{ $entry->article?->name }}@if($entry->isDish()) <span class="text-xs text-gray-500">({{ __('enligt receptet') }})</span>@endif @if($entry->corrects_entry_id)<x-ui.badge>{{ __('Rättelse') }}</x-ui.badge>@endif</td>
                                <td class="py-2 pr-4 text-right tabular-nums {{ $entry->quantity_milli < 0 ? 'text-accent-700' : '' }}">{{ $qty($entry->quantity_milli) }} {{ $entry->isDish() ? '' : $entry->article?->unit }}</td>
                                <td class="py-2 pr-4">{{ $entry->reason->label() }}@if($entry->recipient) · {{ $entry->recipient }}@endif @if($entry->note)<span class="block text-xs text-gray-500">{{ $entry->note }}</span>@endif</td>
                                <td class="py-2 pr-4">{{ $entry->creator?->name }}</td>
                                <td class="py-2 pr-4 text-right">
                                    @if($canRegister && $entry->corrects_entry_id === null && $entry->correction === null)
                                        <button type="button" wire:click="correct({{ $entry->id }})" wire:confirm="{{ __('Rätta raden? Varorna läggs tillbaka i lagret och en rättelserad läggs till i loggen. Raden kan inte tas bort.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Rätta') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $entries->links() }}</div>
        @endif
    </x-ui.card>
</div>
