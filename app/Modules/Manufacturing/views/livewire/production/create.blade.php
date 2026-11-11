@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-6">
    <x-ui.card>
        <form wire:submit="save" class="grid gap-5 sm:grid-cols-6">
            <fieldset class="sm:col-span-6">
                <legend class="text-sm font-semibold text-ink">{{ __('Tillverka') }}</legend>
                <div class="mt-2 flex flex-wrap gap-6">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" wire:model.live="form.source" value="artikel" class="border-stone-300 text-ink focus:ring-accent-500">
                        {{ __('Mot lager, från en artikel') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" wire:model.live="form.source" value="kundorder" class="border-stone-300 text-ink focus:ring-accent-500">
                        {{ __('Till en rad på en kundorder') }}
                    </label>
                </div>
            </fieldset>

            @if($fromOrder)
                <x-ui.field for="to-sales-order" :label="__('Kundorder')" error="form.sales_document_id" class="sm:col-span-3">
                    <select id="to-sales-order" wire:model.live="salesDocumentId" class="{{ $input }}">
                        <option value="">{{ __('Välj kundorder') }}</option>
                        @foreach($salesOrders as $option)
                            <option value="{{ $option->id }}">{{ $option->title() }} · {{ $option->customer?->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="to-line" :label="__('Rad')" error="form.line_key" class="sm:col-span-3" :hint="__('Antalet och radens egen stycklista följer med. Har raden ingen egen stycklista används artikelns.')">
                    <select id="to-line" wire:model.live="lineKey" class="{{ $input }}" @disabled($rows->isEmpty())>
                        <option value="">{{ __('Välj rad') }}</option>
                        @foreach($rows as $row)
                            <option value="{{ $row->line_key }}">{{ $row->article?->number }} {{ $row->description }} · {{ $row->quantity }} {{ $row->unit }}@if($copies->has($row->line_key)) · {{ __('egen stycklista') }}@elseif(! $withBom->has($row->article_id)) · {{ __('saknar stycklista') }}@endif</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if($salesOrders->isEmpty())
                    <p class="text-sm text-gray-600 sm:col-span-6">{{ __('Det finns inga öppna kundordrar.') }}</p>
                @endif
            @else
                <x-ui.field for="to-article" :label="__('Produkt')" error="form.article_id" class="sm:col-span-3" :hint="__('Artiklar med en stycklista. Produkten måste vara lagerförd för att kunna tas emot i lagret.')">
                    <select id="to-article" wire:model.live="form.article_id" class="{{ $input }}">
                        <option value="">{{ __('Välj produkt') }}</option>
                        @foreach($articles as $option)
                            <option value="{{ $option->id }}">{{ $option->number }} {{ $option->name }}@if(! $option->is_stock_item) ({{ __('ej lagerförd') }})@endif</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if($articles->isEmpty())
                    <p class="text-sm text-gray-600 sm:col-span-3">{{ __('Inga produkter har en stycklista ännu.') }} <a href="{{ route('manufacturing.boms.create') }}" class="font-semibold text-accent-700 underline">{{ __('Lägg upp en stycklista') }}</a></p>
                @endif
            @endif

            <x-ui.field for="to-quantity" :label="__('Antal')" error="form.quantity" class="sm:col-span-2" :hint="$fromOrder ? __('Tomt: radens antal.') : null">
                <input id="to-quantity" wire:model="form.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums" @required(! $fromOrder)>
            </x-ui.field>
            <x-ui.field for="to-unit" :label="__('Enhet')" error="form.unit" class="sm:col-span-1">
                <select id="to-unit" wire:model="form.unit" class="{{ $input }}">
                    @foreach($units as $unit)
                        <option value="{{ $loop->first ? '' : $unit }}">{{ $unit }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="to-location" :label="__('Tas emot på')" error="form.location_id" class="sm:col-span-3" :hint="__('Lagerstället där de färdiga produkterna ställs. Tomt: produktens standardplats.')">
                <select id="to-location" wire:model="form.location_id" class="{{ $input }}">
                    <option value="">{{ __('Produktens standardplats') }}</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}">{{ $location->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="to-start" :label="__('Planerad start')" error="form.planned_start_on" class="sm:col-span-2">
                <input id="to-start" type="date" wire:model="form.planned_start_on" class="{{ $input }}">
            </x-ui.field>
            <x-ui.field for="to-due" :label="__('Klart senast')" error="form.due_on" class="sm:col-span-2">
                <input id="to-due" type="date" wire:model="form.due_on" class="{{ $input }}">
            </x-ui.field>
            <x-ui.field for="to-notes" :label="__('Anteckningar för verkstaden')" error="form.notes" class="sm:col-span-6">
                <textarea id="to-notes" wire:model="form.notes" rows="3" maxlength="2000" class="{{ $input }}"></textarea>
            </x-ui.field>
            <div class="flex flex-wrap gap-3 sm:col-span-6">
                <x-button type="submit" wire:loading.attr="disabled">{{ __('Skapa tillverkningsorder') }}</x-button>
                <a href="{{ route('manufacturing.productionOrders.index') }}" class="inline-flex items-center rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-stone-50">{{ __('Avbryt') }}</a>
            </div>
        </form>
    </x-ui.card>
</div>
