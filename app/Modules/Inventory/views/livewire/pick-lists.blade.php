@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli);
@endphp
<div class="space-y-6">
    @if($creating !== null)
        <x-ui.card :title="match ($creating) { 'order' => __('Plocklista från order'), 'invoice' => __('Plocklista från faktura'), default => __('Plocklista för hand') }">
            <form wire:submit="make" class="space-y-5">
                @if($creating === 'order')
                    <x-ui.field for="pick-order" :label="__('Order')" error="source">
                        <select id="pick-order" wire:model="sourceId" class="{{ $input }}" required>
                            <option value="">{{ __('Välj order') }}</option>
                            @foreach($orders as $order)
                                <option value="{{ $order->id }}">{{ __('Order :number', ['number' => $order->number]) }} · {{ $order->customer?->name }} · {{ $order->document_date->toDateString() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @elseif($creating === 'invoice')
                    <x-ui.field for="pick-invoice" :label="__('Faktura')" error="source">
                        <select id="pick-invoice" wire:model="sourceId" class="{{ $input }}" required>
                            <option value="">{{ __('Välj faktura') }}</option>
                            @foreach($invoices as $invoice)
                                <option value="{{ $invoice->id }}">{{ $invoice->number ? __('Faktura :number', ['number' => $invoice->number]) : __('Utkast') }} · {{ $invoice->customer?->name }} · {{ $invoice->invoice_date->toDateString() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @else
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-semibold text-ink">{{ __('Rader') }}</legend>
                        @foreach($rows as $index => $row)
                            <div class="grid gap-3 sm:grid-cols-6" wire:key="pick-row-{{ $index }}">
                                <x-ui.field for="pick-row-article-{{ $index }}" :label="__('Artikel')" error="rows.{{ $index }}.article_id" class="sm:col-span-4">
                                    <select id="pick-row-article-{{ $index }}" wire:model="rows.{{ $index }}.article_id" class="{{ $input }}">
                                        <option value="">{{ __('Välj artikel') }}</option>
                                        @foreach($articles as $article)
                                            <option value="{{ $article->id }}">{{ $article->number }} {{ $article->name }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                <x-ui.field for="pick-row-quantity-{{ $index }}" :label="__('Antal')" error="rows.{{ $index }}.quantity" class="sm:col-span-2">
                                    <input id="pick-row-quantity-{{ $index }}" wire:model="rows.{{ $index }}.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                                </x-ui.field>
                            </div>
                        @endforeach
                        <button type="button" wire:click="addRow" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till rad') }}</button>
                    </fieldset>
                @endif
                <div class="flex flex-wrap gap-3">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Skapa plocklista') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($current)
        <x-ui.card :title="__('Plocklista :number', ['number' => $current->number])" :description="trim(($current->source_label ?? '').' '.($current->customer?->name ?? ''))">
            <x-slot:action>
                <span class="flex gap-3 text-sm font-semibold">
                    <a href="{{ route('inventory.pickLists.pdf', $current->id) }}" class="text-accent-700 underline" target="_blank" rel="noopener">{{ __('Skriv ut (PDF)') }}</a>
                    <button type="button" wire:click="close" class="text-gray-700 underline">{{ __('Stäng') }}</button>
                </span>
            </x-slot:action>
            <ol class="space-y-3">
                @foreach($current->lines as $line)
                    <li class="grid gap-3 rounded-2xl bg-stone-50 p-4 sm:grid-cols-12 sm:items-center" wire:key="pick-line-{{ $line->id }}">
                        <span class="text-2xl font-bold tabular-nums text-ink sm:col-span-2">{{ $line->location?->full_code ?? __('Ingen plats') }}</span>
                        <span class="flex items-center gap-3 sm:col-span-6">
                            @if($line->article?->mainImage)
                                <img src="{{ route('inventory.articleImages.show', ['article' => $line->article->id, 'image' => $line->article->mainImage->id, 'storlek' => 'liten']) }}" alt="" class="size-12 shrink-0 rounded-lg object-cover ring-1 ring-stone-200" loading="lazy">
                            @endif
                            <span>
                                <span class="font-semibold tabular-nums">{{ $line->article?->number }}</span> {{ $line->article?->name }}
                                <span class="block text-sm text-gray-600">{{ __('Plocka :quantity :unit', ['quantity' => $q($line->quantity_milli), 'unit' => $line->article?->unit]) }}@if($line->article?->gtin) · EAN {{ $line->article->gtin }}@endif</span>
                            </span>
                        </span>
                        @if($current->status === 'OPEN' && $canCount)
                            <x-ui.field for="picked-{{ $line->id }}" :label="__('Plockat')" error="picked.{{ $line->id }}.picked" class="sm:col-span-4">
                                <input id="picked-{{ $line->id }}" wire:model.blur="picked.{{ $line->id }}" inputmode="decimal" class="{{ $input }} min-h-11 text-right text-lg tabular-nums">
                            </x-ui.field>
                        @else
                            <span class="text-right tabular-nums sm:col-span-4">{{ __('Plockat :quantity', ['quantity' => $q($line->picked_milli)]) }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
            @if($current->status === 'OPEN' && $canCount)
                <div class="mt-5 flex flex-wrap items-end gap-4">
                    <x-ui.field for="pick-date" :label="__('Plockat den')" error="date">
                        <input id="pick-date" type="date" wire:model="date" class="{{ $input }}">
                    </x-ui.field>
                    <x-button type="button" wire:click="complete" wire:confirm="{{ __('Klarmarkera plocklistan? Det plockade dras från lagret och kan bara rättas med en ny rörelse.') }}" wire:loading.attr="disabled">{{ __('Klar, dra från lagret') }}</x-button>
                    <button type="button" wire:click="cancelList({{ $current->id }})" wire:confirm="{{ __('Makulera plocklistan? Inget dras från lagret.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Makulera') }}</button>
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($canCount && $creating === null)
            <x-slot:cta>
                <span class="flex flex-wrap gap-3">
                    <x-button type="button" wire:click="create('order')">{{ __('Från order') }}</x-button>
                    <button type="button" wire:click="create('invoice')" class="text-sm font-semibold text-accent-700 underline">{{ __('Från faktura') }}</button>
                    <button type="button" wire:click="create('manual')" class="text-sm font-semibold text-accent-700 underline">{{ __('För hand') }}</button>
                </span>
            </x-slot:cta>
        @endif

        <div class="mb-4">
            <label class="block max-w-xs">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="oppna">{{ __('Att plocka') }}</option>
                    <option value="alla">{{ __('Alla') }}</option>
                </select>
            </label>
        </div>

        @if($lists->isEmpty())
            <x-ui.empty-state :title="__('Inga plocklistor')">{{ __('Gör en plocklista från en order eller faktura med lagerförda artiklar. Raderna sorteras efter plats så att du går genom lagret en gång.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-stone-200">
                @foreach($lists as $list)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="pick-list-{{ $list->id }}">
                        <span>
                            <span class="font-semibold tabular-nums">{{ __('Plocklista :number', ['number' => $list->number]) }}</span>
                            <span class="text-gray-700">{{ $list->source_label }} {{ $list->customer?->name }}</span>
                            <x-ui.badge :tone="$list->status === 'COMPLETED' ? 'success' : ($list->status === 'OPEN' ? 'accent' : 'neutral')">{{ $list->statusLabel() }}</x-ui.badge>
                            <span class="block text-sm text-gray-500">{{ __('Rader: :count', ['count' => $list->lines_count]) }} · {{ $list->created_at->toDateString() }}</span>
                        </span>
                        <button type="button" wire:click="show({{ $list->id }})" class="text-sm font-semibold text-accent-700 underline">{{ $list->status === 'OPEN' ? __('Plocka') : __('Visa') }}</button>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $lists->links() }}</div>
        @endif
    </x-ui.card>
</div>
