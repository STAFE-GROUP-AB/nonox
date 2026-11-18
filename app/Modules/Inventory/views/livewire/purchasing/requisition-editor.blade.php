@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<form wire:submit="save" class="space-y-6">
    <datalist id="pr-accounts">
        @foreach($accounts as $account)
            <option value="{{ $account->number }}">{{ $account->name }}</option>
        @endforeach
    </datalist>

    @if($existing?->status === App\Modules\Inventory\Domain\PurchaseRequisitionStatus::Rejected)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            {{ __('Anmodan har avvisats. När du sparar den blir den ett utkast som du skickar till attest igen.') }}
        </div>
    @endif

    <x-ui.card :title="__('Anmodan')">
        <div class="grid gap-5 sm:grid-cols-6">
            <x-ui.field for="pr-needed" :label="__('Behövs senast')" error="form.needed_by" class="sm:col-span-2">
                <input id="pr-needed" type="date" wire:model="form.needed_by" class="{{ $input }}">
            </x-ui.field>
            <x-ui.field for="pr-reason" :label="__('Skäl')" error="form.reason" class="sm:col-span-4" :hint="__('Varför behövs det? Skriv inte uppgifter om personer.')">
                <textarea id="pr-reason" wire:model="form.reason" rows="2" maxlength="500" class="{{ $input }}"></textarea>
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Rader')" :description="__('Välj en artikel eller beskriv med egna ord. Det som inte är en lagerartikel behöver ett konto, så att kostnaden hamnar rätt när fakturan kommer. Priset är ett estimat, och du kan lämna det tomt.')">
        <div class="space-y-3">
            @foreach($form['rows'] as $index => $row)
                <div class="grid gap-3 rounded-2xl bg-stone-50 p-3 sm:grid-cols-12" wire:key="pr-row-{{ $index }}">
                    <x-ui.field for="pr-row-article-{{ $index }}" :label="__('Artikel')" error="form.rows.{{ $index }}.article_id" class="sm:col-span-4">
                        <select id="pr-row-article-{{ $index }}" wire:model.live="form.rows.{{ $index }}.article_id" class="{{ $input }}">
                            <option value="">{{ __('Ingen artikel (egen beskrivning)') }}</option>
                            @foreach($articles as $article)
                                <option value="{{ $article->id }}">{{ $article->number }} {{ $article->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="pr-row-description-{{ $index }}" :label="__('Beskrivning')" error="form.rows.{{ $index }}.description" class="sm:col-span-4" :hint="($row['article_id'] ?? '') !== '' ? __('Tomt: artikelns namn.') : null">
                        <input id="pr-row-description-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.description" maxlength="255" class="{{ $input }}" placeholder="{{ __('Till exempel Hyra av lift, 2 dagar') }}">
                    </x-ui.field>
                    <x-ui.field for="pr-row-quantity-{{ $index }}" :label="__('Antal')" error="form.rows.{{ $index }}.quantity" class="sm:col-span-2">
                        <input id="pr-row-quantity-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                    </x-ui.field>
                    <x-ui.field for="pr-row-unit-{{ $index }}" :label="__('Enhet')" error="form.rows.{{ $index }}.unit_id" class="sm:col-span-2">
                        <select id="pr-row-unit-{{ $index }}" wire:model="form.rows.{{ $index }}.unit_id" class="{{ $input }}">
                            @foreach($unitChoices[$index] ?? [] as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->code }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="pr-row-price-{{ $index }}" :label="__('Estimerat à-pris')" error="form.rows.{{ $index }}.price" class="sm:col-span-2">
                        <input id="pr-row-price-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.price" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                    </x-ui.field>
                    <x-ui.field for="pr-row-account-{{ $index }}" :label="__('Konto')" error="form.rows.{{ $index }}.account" class="sm:col-span-2">
                        <input id="pr-row-account-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.account" list="pr-accounts" inputmode="numeric" maxlength="10" class="{{ $input }} tabular-nums">
                    </x-ui.field>
                    <x-ui.field for="pr-row-cost-center-{{ $index }}" :label="__('Kostnadsställe')" error="form.rows.{{ $index }}.cost_center" class="sm:col-span-2">
                        <input id="pr-row-cost-center-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.cost_center" maxlength="50" class="{{ $input }}">
                    </x-ui.field>
                    <x-ui.field for="pr-row-project-{{ $index }}" :label="__('Projekt')" error="form.rows.{{ $index }}.project" class="sm:col-span-2">
                        <input id="pr-row-project-{{ $index }}" wire:model.blur="form.rows.{{ $index }}.project" maxlength="50" class="{{ $input }}">
                    </x-ui.field>
                    <x-ui.field for="pr-row-supplier-{{ $index }}" :label="__('Önskad leverantör')" error="form.rows.{{ $index }}.supplier_id" class="sm:col-span-4">
                        <select id="pr-row-supplier-{{ $index }}" wire:model="form.rows.{{ $index }}.supplier_id" class="{{ $input }}">
                            <option value="">{{ __('Inköparen väljer') }}</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    @if(count($form['rows']) > 1)
                        <div class="flex items-end sm:col-span-12">
                            <button type="button" wire:click="removeRow({{ $index }})" class="min-h-11 text-sm font-medium text-gray-700 underline">{{ __('Ta bort raden') }}</button>
                        </div>
                    @endif
                </div>
            @endforeach
            @error('form.rows')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <button type="button" wire:click="addRow" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till rad') }}</button>
        </div>
    </x-ui.card>

    <div class="flex flex-wrap gap-3">
        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara utkast') }}</x-button>
        <a href="{{ $existing ? route('inventory.requisitions.show', $existing->id) : route('inventory.requisitions') }}" class="inline-flex items-center rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-stone-50">{{ __('Avbryt') }}</a>
    </div>
</form>
