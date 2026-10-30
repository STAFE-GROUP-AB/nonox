@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $cell = 'w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-md text-sm';
    $fileUrl = $invoice?->file_path ? route('payables.invoices.file', $invoice) : null;
    $selectedSupplier = $suppliers->firstWhere('id', (int) $supplierId);
@endphp

<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
    {{-- The invoice itself, beside the form, so it can be copied field by field. --}}
    <x-ui.card :title="__('Fakturan')">
        @if($file && str_starts_with((string) $file->getMimeType(), 'image/'))
            <img src="{{ $file->temporaryUrl() }}" alt="{{ $file->getClientOriginalName() }}" class="w-full rounded-lg border border-gray-200 object-contain">
        @elseif($file)
            <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-700">{{ __(':name är vald. Den sparas och visas här när du sparar utkastet.', ['name' => $file->getClientOriginalName()]) }}</p>
        @elseif($fileUrl && str_starts_with((string) $invoice->file_mime, 'image/'))
            <img src="{{ $fileUrl }}" alt="{{ $invoice->file_name }}" class="w-full rounded-lg border border-gray-200 object-contain">
        @elseif($fileUrl)
            <iframe src="{{ $fileUrl }}" title="{{ $invoice->file_name }}" class="h-[40rem] w-full rounded-lg border border-gray-200 bg-gray-50"></iframe>
        @endif

        <div
            x-data="{ over: false }"
            x-on:dragover.prevent="over = true"
            x-on:dragleave.prevent="over = false"
            x-on:drop.prevent="over = false; $wire.upload('file', $event.dataTransfer.files[0])"
            :class="over ? 'border-accent-500 bg-accent-50' : 'border-gray-300'"
            @class(['rounded-lg border-2 border-dashed px-4 py-6 text-center text-sm', 'mt-4' => $file || $fileUrl])
        >
            <p class="text-gray-700">{{ $fileUrl || $file ? __('Byt fil: dra hit en ny, eller') : __('Dra hit fakturan som PDF eller bild, eller') }}
                <label for="supplier_invoice_file" class="cursor-pointer font-semibold text-accent-600 hover:text-accent-700">{{ __('välj fil') }}</label>
            </p>
            <input id="supplier_invoice_file" type="file" class="sr-only" wire:model="file" accept="application/pdf,image/jpeg,image/png,image/webp">
            <p class="mt-1 text-xs text-gray-500" wire:loading wire:target="file">{{ __('Laddar upp…') }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ __('Fakturan blir underlag till verifikationen när den bokförs.') }}</p>
        </div>
        @error('file') <p class="mt-2 text-sm text-accent-700">{{ $message }}</p> @enderror
        @include('ai::partials.analyze-panel', ['canAnalyze' => $aiAvailable && ($file || $fileUrl)])
    </x-ui.card>

    <div class="space-y-6" x-data="supplierRows($wire.entangle('rows'), $wire.entangle('total'), $wire.entangle('vat'), $wire.entangle('vatTreatment'))">
        @if($errors->has('invoice'))
            <div class="rounded-xl border border-accent-200 bg-accent-50 px-5 py-4 text-sm text-accent-800" role="alert">{{ $errors->first('invoice') }}</div>
        @endif

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.field for="supplier_invoice_supplier" :label="__('Leverantör')" error="supplier_id" class="sm:col-span-2">
                    @if($suppliers->isEmpty())
                        <p class="mt-2 text-sm text-gray-600">{{ __('Du har inga leverantörer ännu.') }} <a href="{{ route('payables.suppliers') }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Lägg till en leverantör') }}</a></p>
                    @else
                        <select id="supplier_invoice_supplier" class="{{ $input }} mt-1" wire:model.live="supplierId">
                            <option value="">{{ __('Välj leverantör…') }}</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}">{{ $supplier->number }} {{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </x-ui.field>
                <x-ui.field for="supplier_invoice_treatment" :label="__('Vem redovisar momsen?')" error="vat_treatment" class="sm:col-span-2">
                    <select id="supplier_invoice_treatment" class="{{ $input }} mt-1" x-model="treatment">
                        @foreach($treatments as $treatment)
                            <option value="{{ $treatment->value }}">{{ $treatment->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-sm text-gray-500" x-show="buyerLiable" x-cloak>{{ __('Fakturan har ingen svensk moms. Du redovisar momsen själv och drar av den samtidigt. Kontot väljs efter momssatsen så att beloppen hamnar i rätt ruta i momsdeklarationen.') }}</p>
                </x-ui.field>
                <label class="flex items-start gap-3 text-sm sm:col-span-2">
                    <input type="checkbox" class="mt-0.5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="credit">
                    <span>
                        <span class="font-medium text-gray-900">{{ __('Det här är en kreditnota') }}</span>
                        <span class="block text-gray-600">{{ __('Skriv beloppen som de står på kreditnotan. Den minskar skulden till leverantören och kvittas mot en faktura eller betalas tillbaka.') }}</span>
                    </span>
                </label>
                <x-ui.field for="supplier_invoice_number" :label="__('Leverantörens fakturanummer')" error="invoice_number">
                    <input id="supplier_invoice_number" type="text" class="{{ $input }} mt-1" wire:model="invoiceNumber" maxlength="50">
                </x-ui.field>
                <x-ui.field for="supplier_invoice_ocr" :label="__('OCR-nummer')" error="ocr" :hint="__('Används när fakturan betalas.')">
                    <input id="supplier_invoice_ocr" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="ocr" maxlength="30">
                </x-ui.field>
                <x-ui.field for="supplier_invoice_date" :label="__('Fakturadatum')" error="invoice_date" :hint="__('Fakturan bokförs på det här datumet.')">
                    <input id="supplier_invoice_date" type="date" class="{{ $input }} mt-1" wire:model="invoiceDate">
                </x-ui.field>
                <x-ui.field for="supplier_invoice_due" :label="__('Förfallodag')" error="due_date" :hint="$selectedSupplier ? __('Tomt: :days dagar efter fakturadatum.', ['days' => $selectedSupplier->payment_terms_days]) : __('Tomt: leverantörens betalningsvillkor.')">
                    <input id="supplier_invoice_due" type="date" class="{{ $input }} mt-1" wire:model="dueDate">
                </x-ui.field>
                <x-ui.field for="supplier_invoice_total" :label="__('Att betala, inkl. moms')" error="total">
                    <input id="supplier_invoice_total" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" x-model="total" x-on:blur="fillSingleRow()" placeholder="0,00">
                </x-ui.field>
                <div x-show="! buyerLiable">
                    <x-ui.field for="supplier_invoice_vat" :label="__('Varav moms')" error="vat" :hint="__('Summan av momsen på fakturan, alla momssatser.')">
                        <input id="supplier_invoice_vat" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" x-model="vat" x-on:blur="fillSingleRow()" placeholder="0,00">
                    </x-ui.field>
                </div>
                <div x-show="buyerLiable" x-cloak class="text-sm">
                    <p class="font-medium text-gray-700">{{ __('Moms du redovisar själv') }}</p>
                    <p class="mt-2 tabular-nums" x-text="buyerVat === null ? '–' : format(buyerVat) + ' kr'"></p>
                    <p class="mt-1 text-gray-500">{{ __('Bokförs som både utgående och ingående moms, så den kostar inget om du har full avdragsrätt.') }}</p>
                </div>
                <x-ui.field for="supplier_invoice_description" :label="__('Vad gäller fakturan? (frivilligt)')" error="description" class="sm:col-span-2">
                    <input id="supplier_invoice_description" type="text" class="{{ $input }} mt-1" wire:model="description" maxlength="255">
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Kontering')" :description="$selectedSupplier?->defaultAccount ? __('Belopp utan moms. Tomt konto: :number :name.', ['number' => $selectedSupplier->defaultAccount->number, 'name' => $selectedSupplier->defaultAccount->name]) : __('Belopp utan moms, per kostnadskonto. Dela upp på flera rader om fakturan gäller olika saker eller restauranger.')">
            <datalist id="supplier-accounts">
                @foreach($accounts as $account)
                    <option value="{{ $account->number }}">{{ $account->name }}</option>
                @endforeach
            </datalist>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="pb-2 pr-2 font-medium w-24" x-show="! buyerLiable">{{ __('Konto') }}</th>
                            <th class="pb-2 pr-2 font-medium w-24" x-show="buyerLiable" x-cloak>{{ __('Momssats') }}</th>
                            <th class="pb-2 pr-2 font-medium">{{ __('Text') }}</th>
                            @if($costCenters->isNotEmpty())
                                <th class="pb-2 pr-2 font-medium w-32">{{ __('Kostnadsställe') }}</th>
                            @endif
                            @if($projects->isNotEmpty())
                                <th class="pb-2 pr-2 font-medium w-32">{{ __('Projekt') }}</th>
                            @endif
                            <th class="pb-2 pr-2 font-medium w-32 text-right">{{ __('Belopp') }}</th>
                            <th class="pb-2 w-8"><span class="sr-only">{{ __('Ta bort') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, index) in rows" :key="index">
                            <tr>
                                <td class="py-1 pr-2" x-show="! buyerLiable"><input type="text" list="supplier-accounts" inputmode="numeric" class="{{ $cell }} tabular-nums" x-model="row.account" data-row-account :placeholder="'{{ $selectedSupplier?->defaultAccount?->number }}'" :aria-label="'{{ __('Konto, rad') }} ' + (index + 1)" autocomplete="off"></td>
                                <td class="py-1 pr-2" x-show="buyerLiable" x-cloak>
                                    <select class="{{ $cell }}" x-model="row.vat_rate" :aria-label="'{{ __('Momssats, rad') }} ' + (index + 1)">
                                        <option value="2500">25 %</option>
                                        <option value="1200">12 %</option>
                                        <option value="600">6 %</option>
                                    </select>
                                </td>
                                <td class="py-1 pr-2 min-w-[10rem]"><input type="text" class="{{ $cell }}" x-model="row.description" maxlength="255" :aria-label="'{{ __('Text, rad') }} ' + (index + 1)"></td>
                                @if($costCenters->isNotEmpty())
                                    <td class="py-1 pr-2">
                                        <select class="{{ $cell }}" x-model="row.cost_center" :aria-label="'{{ __('Kostnadsställe, rad') }} ' + (index + 1)">
                                            <option value="">–</option>
                                            @foreach($costCenters as $costCenter)
                                                <option value="{{ $costCenter->code }}">{{ $costCenter->code }} {{ $costCenter->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endif
                                @if($projects->isNotEmpty())
                                    <td class="py-1 pr-2">
                                        <select class="{{ $cell }}" x-model="row.project" :aria-label="'{{ __('Projekt, rad') }} ' + (index + 1)">
                                            <option value="">–</option>
                                            @foreach($projects as $project)
                                                <option value="{{ $project->code }}">{{ $project->code }} {{ $project->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endif
                                <td class="py-1 pr-2">
                                    <input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" x-model="row.amount" x-on:dblclick="fillRest(index)" :aria-label="'{{ __('Belopp, rad') }} ' + (index + 1)">
                                </td>
                                <td class="py-1 text-center"><button type="button" class="text-gray-400 hover:text-accent-600" x-on:click="removeRow(index)" :aria-label="'{{ __('Ta bort rad') }} ' + (index + 1)">×</button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm">
                <button type="button" class="font-medium text-accent-600 hover:text-accent-700" x-on:click="addRow()">+ {{ __('Lägg till rad') }}</button>
                <p class="tabular-nums" aria-live="polite">
                    <template x-if="remaining === null"><span class="text-accent-700">{{ __('Ett belopp går inte att läsa.') }}</span></template>
                    <template x-if="remaining === 0"><span class="font-medium text-gray-900">✓ {{ __('Raderna och momsen stämmer med fakturan.') }}</span></template>
                    <template x-if="remaining !== null && remaining !== 0">
                        <span>
                            <span class="text-gray-600">{{ __('Kvar att fördela:') }}</span>
                            <span class="font-semibold text-accent-700" x-text="format(remaining) + ' kr'"></span>
                            <button type="button" class="ml-2 font-medium text-accent-600 hover:text-accent-700" x-on:click="fillRest(rows.length - 1)">{{ __('Lägg på sista raden') }}</button>
                        </span>
                    </template>
                </p>
            </div>

            @foreach($errors->getMessages() as $key => $messages)
                @if(str_starts_with($key, 'rows'))
                    @foreach($messages as $message)
                        <p class="mt-2 text-sm text-accent-700">{{ $message }}</p>
                    @endforeach
                @endif
            @endforeach
        </x-ui.card>

        <div class="flex flex-wrap items-center justify-end gap-3" x-data="{ confirming: false, removing: false }">
            @if($invoice)
                <span x-show="! removing">
                    <button type="button" class="text-sm text-gray-600 underline" x-on:click="removing = true">{{ __('Ta bort utkastet') }}</button>
                </span>
                <span x-show="removing" x-cloak class="flex items-center gap-2 text-sm">
                    <button type="button" class="font-medium text-accent-700 underline" wire:click="delete">{{ __('Ja, ta bort') }}</button>
                    <button type="button" class="text-gray-600 underline" x-on:click="removing = false">{{ __('Avbryt') }}</button>
                </span>
            @endif
            <a href="{{ route('payables.invoices.index') }}" class="text-sm text-gray-600 underline">{{ __('Tillbaka') }}</a>
            <button type="button" class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50" wire:click="save" wire:loading.attr="disabled">{{ __('Spara som utkast') }}</button>
            @if($canRegister)
                <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ __('Registrera fakturan') }}</x-button>
                <span x-show="confirming" x-cloak class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="text-gray-700">{{ __('Fakturan får ett löpnummer, bokförs och kan sedan inte ändras. Den går vidare till attest.') }}</span>
                    <x-button type="button" wire:click="register" x-on:click="confirming = false" wire:loading.attr="disabled">{{ __('Ja, registrera') }}</x-button>
                    <button type="button" class="text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                </span>
            @endif
        </div>
    </div>
</div>
