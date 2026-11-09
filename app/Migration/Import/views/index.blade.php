@php($current = auth()->user()->currentCompany())
<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Läs in register')" :lead="__('Flytta in kunder, leverantörer, artiklar, obetalda fakturor och underlag från ett annat program.')" />

        <x-ui.card :title="__('Så går det till')">
            <ol class="list-decimal space-y-2 ps-5 text-gray-700">
                <li>{{ __('Läs först in bokföringen som SIE-fil under Läs in SIE-fil. Registren och fakturorna hör ihop med den.') }}</li>
                <li>{{ __('Ta ut filen ur ditt gamla program som CSV eller Excel och ladda upp den här. Ingenting ändras än.') }}</li>
                <li>{{ __('Koppla filens kolumner till NONOX fält. Väljer du ett förval fylls kopplingen i åt dig, och du kan ändra den och spara den som Mitt format.') }}</li>
                <li>{{ __('Läs rapporten: vad som läggs till, vad som hoppas över och vad som har fel. Läs sedan in filen.') }}</li>
            </ol>
            <p class="mt-4 text-sm text-gray-600">{{ __('Läs in kunder och leverantörer före obetalda fakturor, så att fakturorna kan kopplas till rätt kund eller leverantör.') }}</p>
            @if($guides)
                <p class="mt-2 text-sm"><a class="font-medium text-accent-600 hover:text-accent-700" href="{{ route('migrationFrom.index') }}">{{ __('Så tar du ut filerna ur ditt gamla program') }}</a></p>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Ladda upp en fil')">
            <p class="mb-4 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-800">{{ __('Filen läses in i :company (:number). Har du flera företag, byt först till det som filen gäller.', ['company' => $current->name, 'number' => $current->org_number]) }}</p>
            <form method="POST" action="{{ route('import.registers.store') }}" enctype="multipart/form-data" class="space-y-4" x-data="{ sending: false, kind: '{{ old('kind', 'customers') }}' }" x-on:submit="sending = true">
                @csrf
                <x-ui.field for="kind" :label="__('Vad innehåller filen?')" :error="'kind'">
                    <select id="kind" name="kind" x-model="kind" class="mt-1 block w-full rounded-xl border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        @foreach($kinds as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <div x-show="kind !== 'attachments'">
                    <x-ui.field for="source" :label="__('Förval för kolumnerna')" :hint="__('Ett förval fyller bara i kopplingen. Du kan ändra den innan något läses in.')">
                        <select id="source" name="source" class="mt-1 block w-full rounded-xl border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                            <option value="nonox">{{ __('Egen fil eller NONOX-mall') }}</option>
                            @foreach($programs as $slug => $name)
                                <option value="{{ $slug }}">{{ $name }}</option>
                            @endforeach
                            @foreach($presets as $preset)
                                <option value="company:{{ $preset->id }}">{{ __('Mitt format') }}: {{ $preset->name }} ({{ \App\Migration\Import\RegisterKind::from($preset->kind)->label() }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                </div>
                <x-ui.field for="file" :label="__('Fil')" :error="'file'">
                    <input id="file" name="file" type="file" required class="mt-1 block w-full text-sm text-gray-700 file:me-4 file:rounded-lg file:border-0 file:bg-accent-50 file:px-4 file:py-2 file:font-medium file:text-accent-700 hover:file:bg-accent-100">
                    <p class="mt-1 text-sm text-gray-500" x-show="kind !== 'attachments'">{{ __('CSV, TSV, text eller Excel (.xlsx). Högst 20 MB och 100 000 rader.') }}</p>
                    <p class="mt-1 text-sm text-gray-500" x-show="kind === 'attachments'" x-cloak>{{ __('En ZIP-fil, högst 100 MB. Lägg en fil som heter lank.csv i ZIP-filen med kolumnerna filnamn;serie;nummer;ar, eller lägg filerna i mappar enligt ÅÅÅÅ/SERIE-NUMMER.pdf. Läs in SIE-filerna först.') }}</p>
                </x-ui.field>
                <x-button x-bind:disabled="sending">
                    <span x-show="! sending">{{ __('Ladda upp och granska') }}</span>
                    <span x-show="sending" x-cloak>{{ __('Laddar upp…') }}</span>
                </x-button>
            </form>
            <p class="mt-4 text-sm text-gray-600">
                {{ __('Ladda ner en mall:') }}
                @foreach($kinds as $kind)
                    @if($kind !== \App\Migration\Import\RegisterKind::Attachments)
                        <a class="ms-2 font-medium text-accent-600 hover:text-accent-700" href="{{ route('import.registers.template', $kind->value) }}">{{ $kind->label() }}</a>
                    @endif
                @endforeach
            </p>
        </x-ui.card>

        <x-ui.card :title="__('Tidigare filer')">
            @if($imports->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Du har inte läst in någon fil ännu.') }}</p>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach($imports as $import)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div>
                                <a href="{{ route('import.registers.show', $import) }}" class="font-medium text-gray-900 hover:text-accent-700">{{ $import->original_name }}</a>
                                <p class="text-sm text-gray-600">
                                    {{ $import->registerKind()->label() }}
                                    · {{ $import->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                    · {{ $import->user?->name }}
                                </p>
                            </div>
                            <x-ui.badge :tone="$import->status === \App\Models\RegisterImport::COMPLETED ? 'success' : 'neutral'">{{ $import->statusLabel() }}</x-ui.badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</x-app-layout>
