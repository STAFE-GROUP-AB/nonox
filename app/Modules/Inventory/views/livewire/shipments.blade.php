@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli);
    $kg = fn (?int $grams): string => $grams === null ? '–' : number_format($grams / 1000, 3, ',', "\u{00A0}").' kg';
    $cmText = fn (?int $mm): string => $mm === null ? '–' : rtrim(rtrim(number_format($mm / 10, 1, ',', ''), '0'), ',');
    $lotText = fn ($lot): string => $lot === null ? '' : ($lot->kind === 'SERIAL' ? __('Serienummer :code', ['code' => $lot->code]) : __('Batch :code', ['code' => $lot->code]));
@endphp
<div class="space-y-6">
    @if($current)
        <x-ui.card :title="__('Försändelse :number', ['number' => $current->number])" :description="trim(__('Plocklista :number', ['number' => $current->pickList?->number]).' · '.($current->customer?->name ?? ''))">
            <x-slot:action>
                <span class="flex gap-3 text-sm font-semibold">
                    <a href="{{ route('inventory.shipments.deliveryNote', $current->id) }}" class="text-accent-700 underline" target="_blank" rel="noopener">{{ __('Följesedel (PDF)') }}</a>
                    <button type="button" wire:click="close" class="text-gray-700 underline">{{ __('Stäng') }}</button>
                </span>
            </x-slot:action>

            <p class="mb-4"><x-ui.badge :tone="$current->status === 'SHIPPED' ? 'success' : ($current->status === 'DRAFT' ? 'accent' : 'neutral')">{{ $current->statusLabel() }}</x-ui.badge>
                @if($current->shipped_at)<span class="text-sm text-gray-600">{{ $current->shipped_at->toDateString() }}</span>@endif
            </p>

            @error('shipment')<p class="mb-3 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror

            <div class="space-y-4">
                @forelse($current->packages as $package)
                    <section class="rounded-2xl bg-stone-50 p-4" wire:key="package-{{ $package->id }}" aria-label="{{ __('Kolli :number', ['number' => $package->position]) }}">
                        <h3 class="text-lg font-bold text-ink">{{ __('Kolli :number', ['number' => $package->position]) }}</h3>

                        @if($current->status === 'DRAFT' && $canCount)
                            <form wire:submit="saveMeasures({{ $package->id }})" class="mt-3 grid gap-3 sm:grid-cols-5 sm:items-end">
                                <x-ui.field for="weight-{{ $package->id }}" :label="__('Vikt (kg)')" error="measures.{{ $package->id }}.weight">
                                    <input id="weight-{{ $package->id }}" wire:model="measures.{{ $package->id }}.weight" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                                </x-ui.field>
                                <x-ui.field for="length-{{ $package->id }}" :label="__('Längd (cm)')" error="measures.{{ $package->id }}.length">
                                    <input id="length-{{ $package->id }}" wire:model="measures.{{ $package->id }}.length" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                                </x-ui.field>
                                <x-ui.field for="width-{{ $package->id }}" :label="__('Bredd (cm)')" error="measures.{{ $package->id }}.width">
                                    <input id="width-{{ $package->id }}" wire:model="measures.{{ $package->id }}.width" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                                </x-ui.field>
                                <x-ui.field for="height-{{ $package->id }}" :label="__('Höjd (cm)')" error="measures.{{ $package->id }}.height">
                                    <input id="height-{{ $package->id }}" wire:model="measures.{{ $package->id }}.height" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                                </x-ui.field>
                                <x-secondary-button type="submit">{{ __('Spara mått') }}</x-secondary-button>
                            </form>
                        @else
                            <p class="mt-1 text-sm text-gray-600">{{ $kg($package->weight_grams) }} · {{ $cmText($package->length_mm) }} × {{ $cmText($package->width_mm) }} × {{ $cmText($package->height_mm) }} cm</p>
                        @endif

                        <ul class="mt-3 divide-y divide-stone-200">
                            @forelse($package->lines as $line)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="package-line-{{ $line->id }}">
                                    <span>
                                        <span class="font-semibold tabular-nums">{{ $line->article?->number }}</span> {{ $line->article?->name }}
                                        @if($line->lot)
                                            <span class="block text-sm text-gray-600">{{ $lotText($line->lot) }}@if($line->lot->expires_on) · {{ __('bäst före :date', ['date' => $line->lot->expires_on->toDateString()]) }}@endif</span>
                                        @endif
                                    </span>
                                    <span class="flex items-center gap-3">
                                        <span class="tabular-nums">{{ $q($line->quantity_milli) }} {{ $line->article?->stockUnitCode() }}</span>
                                        @if($current->status === 'DRAFT' && $canCount)
                                            <button type="button" wire:click="unpack({{ $line->id }})" class="text-sm font-semibold text-gray-700 underline">{{ __('Ta ur') }}</button>
                                        @endif
                                    </span>
                                </li>
                            @empty
                                <li class="py-2 text-sm text-gray-600">{{ __('Kollit är tomt.') }}</li>
                            @endforelse
                        </ul>

                        @if($current->status === 'DRAFT' && $canCount)
                            @if($items !== [])
                                <form wire:submit="putIn({{ $package->id }})" class="mt-3 grid gap-3 sm:grid-cols-6 sm:items-end">
                                    <x-ui.field for="put-item-{{ $package->id }}" :label="__('Lägg i kollit')" error="put.{{ $package->id }}.item" class="sm:col-span-3">
                                        <select id="put-item-{{ $package->id }}" wire:model="put.{{ $package->id }}.item" class="{{ $input }}">
                                            <option value="">{{ __('Välj vara') }}</option>
                                            @foreach($items as $key => $item)
                                                <option value="{{ $key }}">{{ $item['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </x-ui.field>
                                    <x-ui.field for="put-quantity-{{ $package->id }}" :label="__('Antal')" error="put.{{ $package->id }}.quantity" class="sm:col-span-2">
                                        <input id="put-quantity-{{ $package->id }}" wire:model="put.{{ $package->id }}.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                                    </x-ui.field>
                                    <x-secondary-button type="submit">{{ __('Lägg i') }}</x-secondary-button>
                                </form>
                            @endif
                            <button type="button" wire:click="removePackage({{ $package->id }})" wire:confirm="{{ __('Ta bort kollit? Varorna i det blir kvar att packa om.') }}" class="mt-3 text-sm font-semibold text-gray-700 underline">{{ __('Ta bort kollit') }}</button>
                        @endif
                    </section>
                @empty
                    <x-ui.empty-state :title="__('Inga kollin ännu')">{{ __('Lägg till ett kolli och fyll det med de plockade varorna, eller packa allt i ett kolli med ett klick.') }}</x-ui.empty-state>
                @endforelse
            </div>

            @if($current->status === 'DRAFT' && $canCount)
                <div class="mt-4 flex flex-wrap gap-3">
                    <x-secondary-button type="button" wire:click="addPackage">{{ __('Lägg till kolli') }}</x-secondary-button>
                    @if($items !== [])
                        <x-secondary-button type="button" wire:click="packAll">{{ __('Packa allt som är kvar i ett nytt kolli') }}</x-secondary-button>
                    @endif
                </div>
            @endif

            @if($items !== [])
                <div class="mt-4">
                    <h3 class="text-sm font-semibold text-ink">{{ __('Kvar att packa') }}</h3>
                    <ul class="mt-1 list-disc ps-5 text-sm text-gray-700">
                        @foreach($items as $key => $item)
                            <li wire:key="remaining-{{ $key }}">{{ $item['label'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($canCount && $current->status !== 'CANCELLED')
                <form wire:submit="{{ $current->status === 'DRAFT' ? 'ship' : 'saveTracking' }}" class="mt-6 grid gap-3 sm:grid-cols-6 sm:items-end">
                    <x-ui.field for="carrier" :label="__('Transportör')" error="carrier" class="sm:col-span-2">
                        <input id="carrier" wire:model="carrier" maxlength="60" class="{{ $input }}">
                    </x-ui.field>
                    <x-ui.field for="tracking" :label="__('Spårningsnummer')" error="tracking_number" class="sm:col-span-2">
                        <input id="tracking" wire:model="tracking" maxlength="60" class="{{ $input }}">
                    </x-ui.field>
                    @if($current->status === 'DRAFT')
                        <x-ui.field for="ship-date" :label="__('Skickad den')" error="date">
                            <input id="ship-date" type="date" wire:model="date" class="{{ $input }}">
                        </x-ui.field>
                        <x-button type="submit" wire:loading.attr="disabled" wire:confirm="{{ __('Skicka försändelsen? Den kan inte ändras efter det.') }}">{{ __('Skicka') }}</x-button>
                    @else
                        <x-secondary-button type="submit">{{ __('Spara') }}</x-secondary-button>
                    @endif
                </form>
                @if($current->status === 'DRAFT')
                    <button type="button" wire:click="cancelShipment" wire:confirm="{{ __('Makulera försändelsen? Kollin tas bort och plocklistan kan packas på nytt.') }}" class="mt-3 text-sm font-semibold text-gray-700 underline">{{ __('Makulera') }}</button>
                @endif
            @elseif($current->carrier || $current->tracking_number)
                <p class="mt-4 text-sm text-gray-700">{{ $current->carrier }} {{ $current->tracking_number }}</p>
            @endif

            @if($deliveryOrder)
                <div class="mt-6">
                    <h3 class="text-sm font-semibold text-ink">{{ __('Levererat på order :number', ['number' => $deliveryOrder->number]) }}</h3>
                    <ul class="mt-2 divide-y divide-stone-200 text-sm">
                        @foreach($delivery as $row)
                            <li class="flex flex-wrap justify-between gap-2 py-2" wire:key="delivery-{{ $row['article_id'] }}">
                                <span>{{ $row['article']?->number }} {{ $row['article']?->name }}</span>
                                <span class="tabular-nums">{{ __(':delivered av :ordered levererade', ['delivered' => $q($row['delivered_milli']), 'ordered' => $q($row['ordered_milli'])]) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($waiting > 0)
            <p class="mb-4 text-sm text-gray-700">{{ trans_choice('{1} En plockad plocklista väntar på att packas.|[2,*] :count plockade plocklistor väntar på att packas.', $waiting, ['count' => $waiting]) }} <a href="{{ route('inventory.pickLists', ['visa' => 'alla']) }}" class="font-semibold text-accent-700 underline">{{ __('Gå till Plocklistor') }}</a></p>
        @endif

        <div class="mb-4">
            <label class="block max-w-xs">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="att-packa">{{ __('Att packa') }}</option>
                    <option value="skickade">{{ __('Skickade') }}</option>
                    <option value="alla">{{ __('Alla') }}</option>
                </select>
            </label>
        </div>

        @if($shipments->isEmpty())
            <x-ui.empty-state :title="__('Inga försändelser')">{{ __('Klarmarkera en plocklista och klicka på Packa. Då skapas en försändelse som du fyller med kollin och skickar.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-stone-200">
                @foreach($shipments as $shipment)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="shipment-{{ $shipment->id }}">
                        <span>
                            <span class="font-semibold tabular-nums">{{ __('Försändelse :number', ['number' => $shipment->number]) }}</span>
                            <span class="text-gray-700">{{ $shipment->customer?->name }}</span>
                            <x-ui.badge :tone="$shipment->status === 'SHIPPED' ? 'success' : ($shipment->status === 'DRAFT' ? 'accent' : 'neutral')">{{ $shipment->statusLabel() }}</x-ui.badge>
                            <span class="block text-sm text-gray-500">{{ __('Plocklista :number', ['number' => $shipment->pickList?->number]) }} · {{ __('Kollin: :count', ['count' => $shipment->packages_count]) }} · {{ ($shipment->shipped_at ?? $shipment->created_at)->toDateString() }}@if($shipment->tracking_number) · {{ $shipment->tracking_number }}@endif</span>
                        </span>
                        <button type="button" wire:click="show({{ $shipment->id }})" class="text-sm font-semibold text-accent-700 underline">{{ $shipment->status === 'DRAFT' ? __('Packa') : __('Visa') }}</button>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $shipments->links() }}</div>
        @endif
    </x-ui.card>
</div>
