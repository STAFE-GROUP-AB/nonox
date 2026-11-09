@php
    use App\Migration\Import\RegisterKind;
    use App\Models\RegisterImport;

    $report = $import->report;
    $options = $import->options;
    $rowLabels = [
        'create' => __('Läggs till'), 'fill' => __('Tomma fält fylls i'), 'exists' => __('Finns redan, hoppas över'),
        'duplicate' => __('Dubblett, hoppas över'), 'excluded' => __('Utesluten'), 'error' => __('Fel, hoppas över'),
        'linked' => __('Kopplas'), 'unlinked' => __('Ingen koppling'), 'rejected' => __('Avvisas'),
    ];
    $editable = in_array($import->status, [RegisterImport::MAPPING, RegisterImport::ANALYSED], true);
    $isZip = $kind === RegisterKind::Attachments;
    $kronor = fn (int $ore) => number_format($ore / 100, 2, ',', ' ').' kr';
    $rows = array_merge((array) ($report['rows'] ?? []), (array) ($report['problems'] ?? []));
@endphp
<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$import->original_name" :lead="$kind->label()" />

        <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
            <x-ui.badge :tone="$import->status === RegisterImport::COMPLETED ? 'success' : 'neutral'">{{ $import->statusLabel() }}</x-ui.badge>
            <span>{{ $import->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
            <span>{{ $import->user?->name }}</span>
            @if($import->file_deleted_at)
                <span>{{ __('Källfilen är raderad.') }}</span>
            @else
                <span>{{ __('Källfilen raderas automatiskt när importen har legat i 30 dagar, eller efter 7 dagar om den inte läses in.') }}</span>
            @endif
        </div>

        @if($import->status === RegisterImport::FAILED && $import->error)
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $import->error }}</div>
        @endif

        @if($import->status === RegisterImport::RUNNING)
            <div class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-800" role="status">{{ __('Filen läses in just nu. Ladda om sidan om en stund.') }}</div>
        @endif

        @if($import->result)
            <x-ui.card :title="__('Resultat')">
                <dl class="grid gap-4 sm:grid-cols-4">
                    <div><dt class="text-sm text-gray-600">{{ __('Rader i filen') }}</dt><dd class="text-xl font-semibold">{{ $import->result['read'] }}</dd></div>
                    <div><dt class="text-sm text-gray-600">{{ $isZip ? __('Underlag kopplade') : __('Tillagda') }}</dt><dd class="text-xl font-semibold">{{ $import->result['created'] }}</dd></div>
                    @unless($isZip)
                        <div><dt class="text-sm text-gray-600">{{ __('Tomma fält ifyllda') }}</dt><dd class="text-xl font-semibold">{{ $import->result['filled'] }}</dd></div>
                    @endunless
                    <div><dt class="text-sm text-gray-600">{{ __('Hoppade över') }}</dt><dd class="text-xl font-semibold">{{ $import->result['skipped'] }}</dd></div>
                    <div><dt class="text-sm text-gray-600">{{ __('Fel') }}</dt><dd class="text-xl font-semibold">{{ $import->result['error_count'] }}</dd></div>
                </dl>
                @if(! empty($import->result['errors']))
                    <ul class="mt-4 list-disc space-y-1 ps-5 text-sm text-gray-700">
                        @foreach($import->result['errors'] as $error)
                            <li>{{ $isZip ? __('Fil :line', ['line' => $error['line']]) : __('Rad :line', ['line' => $error['line']]) }}: {{ $error['message'] }}</li>
                        @endforeach
                    </ul>
                @endif
                @if($kind->isOpenItems() && $import->status === RegisterImport::COMPLETED)
                    <p class="mt-4 text-sm text-gray-600">{{ __('Fakturorna är skapade utan verifikation och har inget nummer i NONOX: de heter som i det tidigare programmet. Saldot på :account kommer från SIE-filen. En betalning bokförs mot :account.', ['account' => $kind->controlAccount()]) }}</p>
                @endif
            </x-ui.card>
        @endif

        @if($editable)
            <form method="POST" action="{{ route('import.registers.analyse', $import) }}" class="space-y-6" x-data="{ sending: false }" x-on:submit="sending = true">
                @csrf

                @unless($isZip)
                    <x-ui.card :title="__('Så läste vi filen')">
                        <p class="text-sm text-gray-700">
                            {{ __('Format') }}: {{ ($detected['format'] ?? 'csv') === 'xlsx' ? 'Excel (.xlsx)' : 'CSV/text' }}
                            @if(($detected['format'] ?? '') === 'csv')
                                · {{ __('Teckenkodning') }}: {{ $detected['encoding'] }}
                                · {{ __('Avgränsare') }}: {{ ['' => '', ';' => __('semikolon'), ',' => __('komma'), "\t" => __('tabb'), '|' => __('lodstreck')][$detected['delimiter'] ?? ''] ?? $detected['delimiter'] }}
                            @endif
                            · {{ count($headers) }} {{ __('kolumner') }}
                        </p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            @if(($detected['format'] ?? '') === 'csv')
                                <x-ui.field for="reader_encoding" :label="__('Teckenkodning')">
                                    <select id="reader_encoding" name="reader[encoding]" class="mt-1 block w-full rounded-xl border-gray-300 text-sm">
                                        @foreach(['auto' => __('Hitta automatiskt'), 'UTF-8' => 'UTF-8', 'Windows-1252' => 'Windows-1252', 'CP437' => 'CP437'] as $value => $label)
                                            <option value="{{ $value }}" @selected(($options['reader']['encoding'] ?? 'auto') === $value || (! isset($options['reader']['encoding']) && $value === 'auto'))>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                <x-ui.field for="reader_delimiter" :label="__('Avgränsare')">
                                    <select id="reader_delimiter" name="reader[delimiter]" class="mt-1 block w-full rounded-xl border-gray-300 text-sm">
                                        @foreach(['auto' => __('Hitta automatiskt'), ';' => __('Semikolon'), ',' => __('Komma'), 'tab' => __('Tabb'), '|' => __('Lodstreck')] as $value => $label)
                                            <option value="{{ $value }}" @selected(($options['reader']['delimiter'] ?? 'auto') === ($value === 'tab' ? "\t" : $value) || (! isset($options['reader']['delimiter']) && $value === 'auto'))>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                            @elseif(count($detected['sheets'] ?? []) > 1)
                                <x-ui.field for="reader_sheet" :label="__('Kalkylblad')">
                                    <select id="reader_sheet" name="reader[sheet]" class="mt-1 block w-full rounded-xl border-gray-300 text-sm">
                                        @foreach($detected['sheets'] as $sheet)
                                            <option value="{{ $sheet }}" @selected(($detected['sheet'] ?? null) === $sheet)>{{ $sheet }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                            @endif
                        </div>
                    </x-ui.card>

                    <x-ui.card :title="__('Koppla kolumnerna')">
                        <p class="mb-4 text-sm text-gray-600">{{ __('Välj vilken kolumn i filen som hör till varje fält i NONOX. Kolumner du inte kopplar ignoreras.') }}</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach($kind->fields() as $field => $meta)
                                <x-ui.field :for="'map_'.$field" :label="$meta['label'].($meta['required'] ? ' *' : '')">
                                    <select id="map_{{ $field }}" name="mapping[{{ $field }}]" class="mt-1 block w-full rounded-xl border-gray-300 text-sm">
                                        <option value="">{{ __('Ignorera') }}</option>
                                        @foreach($headers as $header)
                                            <option value="{{ $header }}" @selected(($options['mapping'][$field] ?? null) === $header)>{{ $header }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                            @endforeach
                        </div>

                        <div class="mt-5 space-y-4">
                            @if(in_array($kind, [RegisterKind::Customers, RegisterKind::Suppliers], true))
                                <label class="flex items-start gap-3 text-sm text-gray-800">
                                    <input type="checkbox" name="fill_empty" value="1" class="mt-1 h-5 w-5 rounded border-gray-300 text-accent-600" @checked($options['fill_empty'] ?? false)>
                                    <span>{{ __('Fyll i tomma fält på poster som redan finns. Det som redan står skrivs aldrig över.') }}</span>
                                </label>
                            @endif
                            @if($kind->isOpenItems())
                                <x-ui.field for="fiscal_year_id" :label="__('Räkenskapsår att stämma av mot')" :hint="__('Summan av obetalda fakturor måste vara lika med saldot på :account vid årets slut.', ['account' => $kind->controlAccount()])">
                                    <select id="fiscal_year_id" name="fiscal_year_id" class="mt-1 block w-full rounded-xl border-gray-300 text-sm sm:w-72">
                                        @foreach($fiscalYears as $year)
                                            <option value="{{ $year->id }}" @selected(($options['fiscal_year_id'] ?? $defaultYearId) === $year->id)>{{ $year->start_date->format('Y-m-d') }} – {{ $year->end_date->format('Y-m-d') }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                            @endif
                            @if($kind->isOpenItems())
                                <x-ui.field for="extra_accounts" :label="__('Fler konton som hör till samma fordran eller skuld')" :hint="__('Konton som ska räknas med i saldot, till exempel 1511. Skriv kontonumren med komma emellan.')">
                                    <input id="extra_accounts" name="extra_accounts" type="text" value="{{ implode(', ', (array) ($options['extra_accounts'] ?? [])) }}" class="mt-1 block w-full rounded-xl border-gray-300 text-sm sm:w-72">
                                </x-ui.field>
                                <label class="flex items-start gap-3 text-sm text-gray-800">
                                    <input type="checkbox" name="match_nonox_number" value="1" class="mt-1 h-5 w-5 rounded border-gray-300 text-accent-600" @checked($options['match_nonox_number'] ?? false)>
                                    <span>{{ __('Numret i filen är kundens eller leverantörens nummer i NONOX. Annars hittas de på numret i det tidigare programmet eller på organisationsnumret.') }}</span>
                                </label>
                            @endif
                            <x-ui.field for="preset_name" :label="__('Spara kopplingen som Mitt format')" :hint="__('Skriv ett namn om du vill kunna välja samma koppling nästa gång. Bara rubrikerna sparas, inga uppgifter.')">
                                <input id="preset_name" name="preset_name" type="text" maxlength="80" class="mt-1 block w-full rounded-xl border-gray-300 text-sm sm:w-72">
                            </x-ui.field>
                        </div>
                    </x-ui.card>
                @endunless

                @if(! empty($report['unmapped']))
                    <x-ui.card :title="__('Koder som behöver förklaras')">
                        <p class="mb-4 text-sm text-gray-600">{{ __('Några värden i filen är koder vi inte kan läsa. Skriv vad varje kod betyder så läses de rätt. Betalningsvillkor skrivs som antal dagar, ja/nej-fält som ja eller nej.') }}</p>
                        <div class="grid gap-4 sm:grid-cols-3">
                            @foreach($report['unmapped'] as $field => $codes)
                                @foreach($codes as $key => $code)
                                    <x-ui.field :for="'vm_'.$field.'_'.$loop->index" :label="$kind->fields()[$field]['label'].': '.$code">
                                        <input id="vm_{{ $field }}_{{ $loop->index }}" name="value_maps[{{ $field }}][{{ $code }}]" type="text" class="mt-1 block w-full rounded-xl border-gray-300 text-sm">
                                    </x-ui.field>
                                @endforeach
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                @if($report)
                    <x-ui.card :title="__('Rapport')">
                        @if($import->blockers() !== [])
                            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                                <p class="font-semibold">{{ __('Filen kan inte läsas in som den är:') }}</p>
                                <ul class="mt-1 list-disc space-y-1 ps-5">
                                    @foreach($import->blockers() as $blocker)
                                        <li>{{ $blocker }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(! empty($report['notes']))
                            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                <ul class="list-disc space-y-1 ps-5">
                                    @foreach($report['notes'] as $note)
                                        <li>{{ $note }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(! empty($report['counts']))
                            <dl class="mt-4 grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                                @foreach($report['counts'] as $key => $count)
                                    <div>
                                        <dt class="text-sm text-gray-600">{{ $key === 'read' ? ($isZip ? __('Filer i ZIP-filen') : __('Rader i filen')) : ($key === 'warnings' ? __('Rader med varning') : ($rowLabels[$key] ?? $key)) }}</dt>
                                        <dd class="text-xl font-semibold">{{ $count }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif

                        @if(! empty($report['reconciliation']))
                            @php($rec = $report['reconciliation'])
                            <div class="mt-4 rounded-xl px-4 py-3 text-sm {{ $rec['equal'] ? 'bg-green-50 text-green-900' : 'bg-red-50 text-red-900' }}">
                                <p class="font-semibold">{{ __('Avstämning mot :account', ['account' => $kind->controlAccount()]) }}</p>
                                <p>{{ __('Obetalt enligt filen') }}: {{ $kronor($rec['file_ore']) }} · {{ __('Öppna fakturor gjorda i NONOX') }}: {{ $kronor($rec['nonox_ore'] ?? 0) }} · {{ __('Saldo i bokföringen') }}: {{ $rec['ledger_ore'] === null ? '–' : $kronor($rec['ledger_ore']) }} · {{ __('Skillnad') }}: {{ $rec['difference_ore'] === null ? '–' : $kronor($rec['difference_ore']) }}</p>
                            </div>
                        @endif

                        @if($rows !== [])
                            <div class="mt-5 overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="text-gray-600">
                                        <tr>
                                            @unless($isZip)<th class="py-2 pe-3">{{ __('Utesluta') }}</th><th class="py-2 pe-3">{{ __('Rad') }}</th>@endunless
                                            <th class="py-2 pe-3">{{ __('Vad händer') }}</th>
                                            <th class="py-2 pe-3">{{ $isZip ? __('Fil') : __('Namn') }}</th>
                                            @unless($isZip)<th class="py-2 pe-3">{{ __('Nummer') }}</th><th class="py-2 pe-3">{{ __('Ort / belopp') }}</th>@endunless
                                            <th class="py-2">{{ __('Att se över') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach($rows as $row)
                                            <tr>
                                                @unless($isZip)
                                                    <td class="py-2 pe-3"><input type="checkbox" name="excluded[]" value="{{ $row['line'] }}" aria-label="{{ __('Utesluta rad :line', ['line' => $row['line']]) }}" @checked(in_array($row['line'], (array) ($options['excluded'] ?? []), true))></td>
                                                    <td class="py-2 pe-3 tabular-nums">{{ $row['line'] }}</td>
                                                @endunless
                                                <td class="py-2 pe-3">{{ $rowLabels[$row['status']] ?? $row['status'] }}</td>
                                                <td class="py-2 pe-3">{{ $row['name'] }} @if(! empty($row['ref']))<span class="text-gray-500">({{ $row['ref'] }})</span>@endif</td>
                                                @unless($isZip)
                                                    <td class="py-2 pe-3 tabular-nums">{{ $row['number'] }}</td>
                                                    <td class="py-2 pe-3">{{ $row['amount'] ?? $row['city'] }}</td>
                                                @endunless
                                                <td class="py-2 text-gray-700">
                                                    @foreach(array_merge((array) ($row['errors'] ?? []), (array) ($row['warnings'] ?? []), array_filter([(string) ($row['reason'] ?? '')])) as $message)
                                                        <div>{{ $message }}</div>
                                                    @endforeach
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <p class="mt-3 text-sm text-gray-600">{{ __('Visar de första raderna och de som har något att se över. Vill du rätta något: rätta filen och ladda upp den igen. Poster som redan lästs in hoppas över. Kryssa i Utesluta på en rad som inte ska med och granska igen.') }}</p>
                        @endif
                    </x-ui.card>
                @endif

                <x-button x-bind:disabled="sending">
                    <span x-show="! sending">{{ $report ? __('Granska igen') : __('Granska filen') }}</span>
                    <span x-show="sending" x-cloak>{{ __('Granskar…') }}</span>
                </x-button>
            </form>

            @if($import->canBeConfirmed())
                <x-ui.card :title="__('Läs in filen')">
                    <p class="text-gray-700">
                        @if($kind->isOpenItems())
                            {{ __('Fakturorna skapas utan verifikation, eftersom saldot på :account redan finns i bokföringen från SIE-filen. De kan inte ändras eller tas bort efteråt, bara betalas, krediteras eller skrivas av.', ['account' => $kind->controlAccount()]) }}
                        @elseif($isZip)
                            {{ __('Filerna läggs som underlag på verifikationerna. Underlag på bokförda verifikationer kan läggas till men inte tas bort.') }}
                        @else
                            {{ __('Posterna läggs till i registret. Poster som redan finns hoppas över. Du kan ändra eller ta bort dem efteråt som vanligt.') }}
                        @endif
                    </p>
                    <form method="POST" action="{{ route('import.registers.confirm', $import) }}" class="mt-5" x-data="{ open: false, sending: false }" x-on:submit="sending = true">
                        @csrf
                        <x-button type="button" x-on:click="open = true" x-show="! open">{{ __('Läs in filen') }}</x-button>
                        <div x-show="open" x-cloak class="flex flex-wrap items-center gap-4">
                            <x-button x-bind:disabled="sending">
                                <span x-show="! sending">{{ __('Ja, läs in :count rader', ['count' => ($report['counts']['create'] ?? 0) + ($report['counts']['fill'] ?? 0) + ($report['counts']['linked'] ?? 0)]) }}</span>
                                <span x-show="sending" x-cloak>{{ __('Läser in…') }}</span>
                            </x-button>
                            <button type="button" class="text-sm text-gray-600 underline" x-on:click="open = false">{{ __('Avbryt') }}</button>
                        </div>
                    </form>
                </x-ui.card>
            @endif
        @endif

        <a href="{{ route('import.registers.index') }}" class="inline-block text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Tillbaka till Läs in register') }}</a>
    </div>
</x-app-layout>
