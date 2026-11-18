@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli);
@endphp
<div class="space-y-6">
    @if($formOpen)
        <x-ui.card :title="$editing ? __('Ändra flyttorder') : __('Ny flyttorder')" :description="__('Välj platsen där varorna ligger och platsen i det andra lagret dit de ska. Lagren måste vara olika.')">
            <form wire:submit="save" class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.field for="transfer-from" :label="__('Från')" error="from_location_id">
                        <select id="transfer-from" wire:model.live="form.from_location_id" class="{{ $input }}" required>
                            <option value="">{{ __('Välj plats') }}</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="transfer-to" :label="__('Till')" error="to_location_id">
                        <select id="transfer-to" wire:model="form.to_location_id" class="{{ $input }}" required>
                            <option value="">{{ __('Välj plats') }}</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                </div>
                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold text-ink">{{ __('Rader') }}</legend>
                    @error('lines')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                    @foreach($rows as $index => $row)
                        @php($article = $articles->firstWhere('id', (int) $row['article_id']))
                        <div class="grid gap-3 sm:grid-cols-12" wire:key="transfer-row-{{ $index }}">
                            <x-ui.field for="transfer-row-article-{{ $index }}" :label="__('Artikel')" error="lines.{{ $index }}.article_id" class="sm:col-span-5">
                                <select id="transfer-row-article-{{ $index }}" wire:model.live="rows.{{ $index }}.article_id" class="{{ $input }}">
                                    <option value="">{{ __('Välj artikel') }}</option>
                                    @foreach($articles as $option)
                                        <option value="{{ $option->id }}">{{ $option->number }} {{ $option->name }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field for="transfer-row-quantity-{{ $index }}" :label="__('Antal')" error="lines.{{ $index }}.quantity" class="sm:col-span-2">
                                <input id="transfer-row-quantity-{{ $index }}" wire:model="rows.{{ $index }}.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                            </x-ui.field>
                            @if($article?->tracking->isTracked())
                                <x-ui.field for="transfer-row-lot-{{ $index }}" :label="$article->tracking->label()" error="lines.{{ $index }}.lot_id" class="sm:col-span-4">
                                    <select id="transfer-row-lot-{{ $index }}" wire:model="rows.{{ $index }}.lot_id" class="{{ $input }}">
                                        <option value="">{{ __('Första batch (bäst före först)') }}</option>
                                        @foreach($lotOptions->get($article->id, collect()) as $option)
                                            <option value="{{ $option->lot_id }}">{{ $option->lot?->code }}@if($option->lot?->expires_on) · {{ $option->lot->expires_on->toDateString() }}@endif · {{ $q($option->quantity_milli) }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                            @endif
                            <div class="flex items-end sm:col-span-1">
                                <button type="button" wire:click="removeRow({{ $index }})" class="pb-2 text-sm font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                            </div>
                        </div>
                    @endforeach
                    <button type="button" wire:click="addRow" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till rad') }}</button>
                </fieldset>
                <x-ui.field for="transfer-note" :label="__('Anteckning')" error="note">
                    <input id="transfer-note" wire:model="form.note" maxlength="255" class="{{ $input }}">
                </x-ui.field>
                <div class="flex flex-wrap gap-3">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara utkast') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancelForm">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($current)
        <x-ui.card :title="__('Flyttorder :number', ['number' => $current->number])" :description="$current->from?->full_code.' → '.$current->to?->full_code">
            <x-slot:action>
                <span class="flex gap-3 text-sm font-semibold">
                    <a href="{{ route('inventory.transfers.pdf', $current->id) }}" class="text-accent-700 underline" target="_blank" rel="noopener">{{ __('Skriv ut (PDF)') }}</a>
                    <button type="button" wire:click="close" class="text-gray-700 underline">{{ __('Stäng') }}</button>
                </span>
            </x-slot:action>
            <p class="mb-4 text-sm text-gray-700">
                <x-ui.badge :tone="$current->status === 'RECEIVED' ? 'success' : ($current->status === 'CANCELLED' ? 'neutral' : 'accent')">{{ $current->statusLabel() }}</x-ui.badge>
                @if($current->sent_on) · {{ __('Skickad :date', ['date' => $current->sent_on->toDateString()]) }}@endif
                @if($current->note) · {{ $current->note }}@endif
            </p>
            <ol class="space-y-3">
                @foreach($current->lines as $line)
                    <li class="grid gap-3 rounded-2xl bg-stone-50 p-4 sm:grid-cols-12 sm:items-center" wire:key="transfer-line-{{ $line->id }}">
                        <span class="sm:col-span-6">
                            <span class="font-semibold tabular-nums">{{ $line->article?->number }}</span> {{ $line->article?->name }}
                            <span class="block text-sm text-gray-600">{{ __('Skickas :quantity :unit', ['quantity' => $q($line->quantity_milli), 'unit' => $line->article?->stockUnitCode()]) }}</span>
                            @if($line->lot)
                                <span class="block text-sm font-semibold text-ink">
                                    {{ $line->lot->kind === 'SERIAL' ? __('Serienummer :code', ['code' => $line->lot->code]) : __('Batch :code', ['code' => $line->lot->code]) }}@if($line->lot->expires_on) · {{ __('bäst före :date', ['date' => $line->lot->expires_on->toDateString()]) }}@endif
                                </span>
                            @elseif($current->status === 'DRAFT' && $line->article?->tracking->isTracked())
                                <span class="block text-sm text-gray-600">{{ __('Batch väljs när ordern skickas (bäst före först).') }}</span>
                            @endif
                        </span>
                        @if($current->isInTransit() && $canCount)
                            <x-ui.field for="arrived-{{ $line->id }}" :label="__('Kom fram (kvar på väg: :open)', ['open' => $q($line->openMilli())])" error="arrived.{{ $line->id }}" class="sm:col-span-6">
                                <input id="arrived-{{ $line->id }}" wire:model="arrived.{{ $line->id }}" inputmode="decimal" class="{{ $input }} min-h-11 text-right text-lg tabular-nums" @disabled($line->openMilli() === 0)>
                            </x-ui.field>
                        @else
                            <span class="text-right text-sm tabular-nums sm:col-span-6">
                                {{ __('Mottaget :quantity', ['quantity' => $q($line->received_milli)]) }}@if($line->returned_milli > 0) · {{ __('Åter till avsändaren :quantity', ['quantity' => $q($line->returned_milli)]) }}@endif
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>
            @if($canCount && in_array($current->status, ['DRAFT', 'SENT', 'PARTIALLY_RECEIVED'], true))
                <div class="mt-5 flex flex-wrap items-end gap-4">
                    <x-ui.field for="transfer-date" :label="$current->status === 'DRAFT' ? __('Skickas den') : __('Datum')" error="date">
                        <input id="transfer-date" type="date" wire:model="date" class="{{ $input }}">
                    </x-ui.field>
                    @if($current->status === 'DRAFT')
                        <x-button type="button" wire:click="send" wire:confirm="{{ __('Skicka flyttordern? Varorna tas från avsändaren och väntar på väg tills de tas emot. Det kan bara rättas med en ny rörelse.') }}" wire:loading.attr="disabled">{{ __('Skicka, varorna är på väg') }}</x-button>
                        <button type="button" wire:click="edit({{ $current->id }})" class="text-sm font-semibold text-accent-700 underline">{{ __('Ändra') }}</button>
                        <button type="button" wire:click="cancelOrder" wire:confirm="{{ __('Avbryta flyttordern? Inget har flyttats.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Avbryt') }}</button>
                    @else
                        <x-button type="button" wire:click="receive" wire:confirm="{{ __('Ta emot det som kom fram? Det läggs in på mottagande plats. Det som inte kom fram blir kvar på väg.') }}" wire:loading.attr="disabled">{{ __('Ta emot') }}</x-button>
                        <button type="button" wire:click="cancelOrder" wire:confirm="{{ __('Avbryta resten? Det som fortfarande är på väg skickas tillbaka till avsändarens plats.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Avbryt resten, tillbaka till avsändaren') }}</button>
                    @endif
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($canCount && ! $formOpen)
            <x-slot:cta>
                <x-button type="button" wire:click="create">{{ __('Ny flyttorder') }}</x-button>
            </x-slot:cta>
        @endif

        <div class="mb-4">
            <label class="block max-w-xs">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="oppna">{{ __('Pågående') }}</option>
                    <option value="alla">{{ __('Alla') }}</option>
                </select>
            </label>
        </div>

        @if($transfers->isEmpty())
            <x-ui.empty-state :title="__('Inga flyttorder')">{{ __('Gör en flyttorder när varor ska från ett lager till ett annat. Medan de är på väg räknas de fortfarande med i lagervärdet.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-stone-200">
                @foreach($transfers as $transfer)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="transfer-{{ $transfer->id }}">
                        <span>
                            <span class="font-semibold tabular-nums">{{ __('Flyttorder :number', ['number' => $transfer->number]) }}</span>
                            <span class="text-gray-700">{{ $transfer->from?->full_code }} → {{ $transfer->to?->full_code }}</span>
                            <x-ui.badge :tone="$transfer->status === 'RECEIVED' ? 'success' : ($transfer->status === 'CANCELLED' ? 'neutral' : 'accent')">{{ $transfer->statusLabel() }}</x-ui.badge>
                            <span class="block text-sm text-gray-500">{{ __('Rader: :count', ['count' => $transfer->lines_count]) }} · {{ $transfer->created_at->toDateString() }}</span>
                        </span>
                        <button type="button" wire:click="show({{ $transfer->id }})" class="text-sm font-semibold text-accent-700 underline">{{ __('Visa') }}</button>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $transfers->links() }}</div>
        @endif
    </x-ui.card>

    @if($belowPoint !== [])
        <x-ui.card :title="__('Lager under beställningspunkten')" :description="__('Per lager, efter de beställningspunkter som satts på artiklarna. Det som är på väg dit räknas med.')">
            <ul class="divide-y divide-stone-200 text-sm">
                @foreach($belowPoint as $row)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><span class="font-semibold">{{ $row['article'] }}</span> · {{ $row['warehouse'] }}</span>
                        <span class="tabular-nums text-gray-700">{{ __('Saldo :have, beställningspunkt :point', ['have' => $q($row['have']), 'point' => $q($row['point'])]) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
