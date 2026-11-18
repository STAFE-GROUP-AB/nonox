@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<form wire:submit="save" class="space-y-6">
    <datalist id="po-accounts">
        @foreach($accounts as $account)
            <option value="{{ $account->number }}">{{ $account->name }}</option>
        @endforeach
    </datalist>
    @if($existing?->sent_at)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            {{ __('Du ändrar version :version av en order som redan har skickats. Den skickade versionen sparas som den var. Skicka ordern igen när du är klar, så att leverantören får den nya versionen.', ['version' => $existing->version]) }}
        </div>
    @endif

    @if($existing?->approval_request_id)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            {{ __('Ordern är attesterad. Sparar du den igen måste den attesteras på nytt innan den kan skickas.') }}
        </div>
    @endif

    <x-ui.card :title="__('Ordern')">
        <div class="grid gap-5 sm:grid-cols-6">
            <x-ui.field for="po-supplier" :label="__('Leverantör')" error="form.supplier_id" class="sm:col-span-3">
                <select id="po-supplier" wire:model="form.supplier_id" class="{{ $input }}" required>
                    <option value="">{{ __('Välj leverantör') }}</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="po-date" :label="__('Orderdatum')" error="form.order_date" class="sm:col-span-1">
                <input id="po-date" type="date" wire:model="form.order_date" class="{{ $input }}">
            </x-ui.field>
            <x-ui.field for="po-delivery" :label="__('Önskat leveransdatum')" error="form.requested_delivery_on" class="sm:col-span-2">
                <input id="po-delivery" type="date" wire:model="form.requested_delivery_on" class="{{ $input }}">
            </x-ui.field>
            <x-ui.field for="po-reference" :label="__('Vår referens')" error="form.our_reference" class="sm:col-span-3">
                <input id="po-reference" wire:model="form.our_reference" maxlength="100" class="{{ $input }}">
            </x-ui.field>
            <x-ui.field for="po-address" :label="__('Leveransadress')" error="form.delivery_address" class="sm:col-span-3" :hint="__('Där varorna ska levereras. Förifylld med lagrets eller företagets adress.')">
                <textarea id="po-address" wire:model="form.delivery_address" rows="3" maxlength="500" class="{{ $input }}"></textarea>
            </x-ui.field>
            <x-ui.field for="po-notes" :label="__('Meddelande till leverantören')" error="form.notes" class="sm:col-span-6">
                <textarea id="po-notes" wire:model="form.notes" rows="2" class="{{ $input }}"></textarea>
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Rader')" :description="__('Skriv antalet i den enhet du köper in i. Sidan visar vad det blir i lagerenheten. Priset är per radens enhet; lämnar du det tomt används artikelns inköpspris.')">
        <div class="space-y-3">
            @foreach($form['rows'] as $index => $row)
                @php($help = $rowHelp[$index] ?? ['units' => [], 'stock' => '', 'price' => ''])
                <div class="grid gap-3 rounded-2xl bg-stone-50 p-3 sm:grid-cols-12" wire:key="po-row-{{ $index }}-{{ $row['id'] ?? 'new' }}">
                    @if($row['free'] ?? false)
                        <x-ui.field for="po-row-description-{{ $index }}" :label="__('Beskrivning')" error="form.rows.{{ $index }}.description" class="sm:col-span-4">
                            <input id="po-row-description-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.description" maxlength="255" class="{{ $input }}" placeholder="{{ __('Till exempel Hyra av lift, 2 dagar') }}">
                        </x-ui.field>
                    @else
                        <x-ui.field for="po-row-article-{{ $index }}" :label="__('Artikel')" error="form.rows.{{ $index }}.article_id" class="sm:col-span-4">
                            <select id="po-row-article-{{ $index }}" wire:model.live="form.rows.{{ $index }}.article_id" class="{{ $input }}" @disabled($row['received'] ?? false)>
                                <option value="">{{ __('Välj artikel') }}</option>
                                @foreach($articles as $article)
                                    <option value="{{ $article->id }}">{{ $article->number }} {{ $article->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif
                    <x-ui.field for="po-row-quantity-{{ $index }}" :label="__('Antal')" error="form.rows.{{ $index }}.quantity" class="sm:col-span-2" :hint="$help['stock'] ?: null">
                        <input id="po-row-quantity-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                    </x-ui.field>
                    <x-ui.field for="po-row-unit-{{ $index }}" :label="__('Enhet')" error="form.rows.{{ $index }}.unit_id" class="sm:col-span-2">
                        <select id="po-row-unit-{{ $index }}" wire:model.live="form.rows.{{ $index }}.unit_id" class="{{ $input }}">
                            @foreach(($row['free'] ?? false) ? $freeUnits : $help['units'] as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->code }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="po-row-price-{{ $index }}" :label="__('À-pris')" error="form.rows.{{ $index }}.price" class="sm:col-span-2">
                        <input id="po-row-price-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.price" inputmode="decimal" placeholder="{{ $help['price'] }}" class="{{ $input }} text-right tabular-nums">
                    </x-ui.field>
                    <x-ui.field for="po-row-expected-{{ $index }}" :label="__('Väntas')" error="form.rows.{{ $index }}.expected_on" class="sm:col-span-2">
                        <input id="po-row-expected-{{ $index }}" type="date" wire:model="form.rows.{{ $index }}.expected_on" class="{{ $input }}">
                    </x-ui.field>
                    @if($row['free'] ?? false)
                        <x-ui.field for="po-row-account-{{ $index }}" :label="__('Konto')" error="form.rows.{{ $index }}.account" class="sm:col-span-2" :hint="__('Där kostnaden bokförs när fakturan kommer.')">
                            <input id="po-row-account-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.account" list="po-accounts" inputmode="numeric" maxlength="10" class="{{ $input }} tabular-nums">
                        </x-ui.field>
                        <x-ui.field for="po-row-cost-center-{{ $index }}" :label="__('Kostnadsställe')" error="form.rows.{{ $index }}.cost_center" class="sm:col-span-2">
                            <input id="po-row-cost-center-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.cost_center" maxlength="50" class="{{ $input }}">
                        </x-ui.field>
                        <x-ui.field for="po-row-project-{{ $index }}" :label="__('Projekt')" error="form.rows.{{ $index }}.project" class="sm:col-span-2">
                            <input id="po-row-project-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.project" maxlength="50" class="{{ $input }}">
                        </x-ui.field>
                        <x-ui.field for="po-row-supplier-number-{{ $index }}" :label="__('Leverantörens art.nr')" error="form.rows.{{ $index }}.supplier_article_number" class="sm:col-span-6">
                            <input id="po-row-supplier-number-{{ $index }}" wire:model="form.rows.{{ $index }}.supplier_article_number" maxlength="60" class="{{ $input }}">
                        </x-ui.field>
                    @else
                        <x-ui.field for="po-row-supplier-number-{{ $index }}" :label="__('Leverantörens art.nr')" error="form.rows.{{ $index }}.supplier_article_number" class="sm:col-span-4" :hint="__('Tomt: artikelns leverantörsnummer.')">
                            <input id="po-row-supplier-number-{{ $index }}" wire:model="form.rows.{{ $index }}.supplier_article_number" maxlength="60" class="{{ $input }}">
                        </x-ui.field>
                    @endif
                    <div class="flex flex-wrap items-end gap-4 sm:col-span-12">
                        @unless($row['received'] ?? false)
                            <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model.live="form.rows.{{ $index }}.free" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                                {{ __('Utan artikel (tjänst eller förbrukning)') }}
                            </label>
                        @endunless
                        @if($row['received'] ?? false)
                            <span class="text-sm text-gray-600">{{ __('Varor har tagits emot eller leveransen är bekräftad på raden.') }}</span>
                        @elseif(count($form['rows']) > 1)
                            <button type="button" wire:click="removeRow({{ $index }})" class="min-h-11 text-sm font-medium text-gray-700 underline">{{ __('Ta bort raden') }}</button>
                        @endif
                    </div>
                </div>
            @endforeach
            @error('form.rows')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <button type="button" wire:click="addRow" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till rad') }}</button>
        </div>
    </x-ui.card>

    <div class="flex flex-wrap gap-3">
        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara utkast') }}</x-button>
        <a href="{{ $existing ? route('inventory.purchaseOrders.show', $existing->id) : route('inventory.purchaseOrders') }}" class="inline-flex items-center rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-stone-50">{{ __('Avbryt') }}</a>
    </div>
</form>
