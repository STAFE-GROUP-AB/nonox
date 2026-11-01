@php
    use App\Modules\Payroll\Livewire\PayrollFileImportPage;
    use App\Modules\Payroll\Livewire\PayrollRunPage;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $sections = ['schematransaktioner' => __('scheman'), 'personal' => __('personuppgifter'), 'resetransaktioner' => __('resor'), 'loneutbetalning' => __('lönebesked'), 'saldon' => __('saldon'), 'dimensioner' => __('dimensioner'), 'resultatenheter' => __('resultatenheter'), 'koder' => __('koder')];
    $filters = ['att-godkanna' => __('Att godkänna'), 'anmarkning' => __('Med anmärkning'), 'godkanda' => __('Godkända'), 'hoppas-over' => __('Läses inte in'), 'alla' => __('Alla')];
    $statuses = ['PROPOSED' => __('Förslag'), 'APPROVED' => __('Godkänd'), 'REJECTED' => __('Avvisad'), 'SKIPPED' => __('Läses inte in')];
    $ready = ($counts['proposed'] ?? 0);
@endphp

<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid gap-x-6 gap-y-1 text-sm text-gray-700 sm:grid-cols-2">
                <div><dt class="inline text-gray-500">{{ __('Källsystem') }}:</dt> <dd class="inline font-medium">{{ $import->source?->name }}</dd></div>
                <div><dt class="inline text-gray-500">{{ __('Format') }}:</dt> <dd class="inline">{{ $import->format === 'PAXML' ? 'PAXml '.$import->format_version : 'CSV' }}</dd></div>
                <div><dt class="inline text-gray-500">{{ __('Inläst') }}:</dt> <dd class="inline">{{ $import->created_at?->format('Y-m-d H:i') }}, {{ $import->uploader?->name }}</dd></div>
                <div><dt class="inline text-gray-500">{{ __('Rader') }}:</dt> <dd class="inline tabular-nums">{{ __(':all rader: :ready att godkänna, :problems med anmärkning, :approved godkända, :skipped läses inte in', ['all' => $counts['all'], 'ready' => $ready, 'problems' => $counts['problems'] ?? 0, 'approved' => $counts['approved'] ?? 0, 'skipped' => ($counts['skipped'] ?? 0) + ($counts['rejected'] ?? 0)]) }}</dd></div>
            </dl>
            <div class="flex flex-wrap items-center gap-3" x-data="{ confirming: false }">
                @if($canDownload)
                    <a href="{{ route('payroll.runs.imports.file', [$run, $import]) }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Hämta originalfilen') }}</a>
                @endif
                @if($canEdit)
                    <button type="button" class="text-sm font-medium text-gray-700 underline" wire:click="reprocess" wire:loading.attr="disabled">{{ __('Läs in filen igen') }}</button>
                    <button type="button" class="text-sm text-gray-600 underline" x-show="! confirming" x-on:click="confirming = true">{{ __('Ta bort filen') }}</button>
                    <span x-show="confirming" x-cloak class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-gray-700">{{ __('Filen och alla dess rader tas bort, även de godkända raderna i lönekörningen.') }}</span>
                        <x-danger-button type="button" wire:click="delete">{{ __('Ja, ta bort') }}</x-danger-button>
                        <button type="button" class="text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                    </span>
                @endif
            </div>
        </div>
        @if(! ($import->report['version_known'] ?? true))
            <p class="mt-4 rounded-lg bg-gray-50 p-4 text-sm text-gray-700" role="status">{{ __('Filen anger PAXml-version :version, som inte finns bland de publicerade versionerna (1.0, 2.0–2.3). Raderna är lästa på samma sätt som i 2.3; kontrollera dem extra noga.', ['version' => $import->format_version ?? '–']) }}</p>
        @endif
        @if(($import->report['ignored_sections'] ?? []) !== [])
            <p class="mt-4 text-sm text-gray-600">
                {{ __('Bara lönetransaktioner och tidtransaktioner läses. Annat i filen lämnas olästa: :sections.', ['sections' => collect($import->report['ignored_sections'])->map(fn ($count, $section) => ($sections[$section] ?? $section).' ('.$count.')')->implode(', ')]) }}
            </p>
        @endif
        <x-input-error for="lines" class="mt-2" />
    </x-ui.card>

    @if($unmapped->isNotEmpty() && $canEdit)
        <x-ui.card :title="__('Koppla koder till lönearter')" :description="__('Välj vilken löneart varje kod i filen blir. Kopplingen sparas för :source och används automatiskt för nästa fil. Ändra den under Företagsinställningar → Moduler → Lön.', ['source' => $import->source?->name])">
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($unmapped as $code)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="map-{{ md5($code) }}">
                        <span class="font-mono font-medium text-gray-900">{{ $code }}</span>
                        <select class="{{ $input }} max-w-sm" aria-label="{{ __('Löneart för :code', ['code' => $code]) }}" wire:change="map(@js($code), $event.target.value)">
                            <option value="">{{ __('Välj löneart…') }}</option>
                            @foreach($payCodes as $payCode)<option value="{{ $payCode->id }}">{{ $payCode->name_sv }} ({{ $payCode->code }})</option>@endforeach
                            <option value="ignore">{{ __('Läs inte in raderna med den här koden') }}</option>
                        </select>
                    </li>
                @endforeach
            </ul>
            <x-input-error for="pay_code_id" class="mt-2" />
        </x-ui.card>
    @endif

    @if($unknownEmployees->isNotEmpty())
        <x-ui.card :title="__('Anställda som inte hittades')" :description="__('Raderna för dem kan inte godkännas. Lägg till anställningsnumret på rätt anställd, eller lägg till den anställda, och läs sedan in filen igen.')">
            <ul class="list-disc space-y-1 pl-5 text-sm text-gray-700">
                @foreach($unknownEmployees as $reference)<li>{{ $reference !== '' ? $reference : __('Utan anställningsnummer och personnummer') }}</li>@endforeach
            </ul>
            <a href="{{ route('payroll.employees') }}" class="mt-3 inline-block text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Gå till Anställda') }}</a>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <nav class="flex flex-wrap gap-2 text-sm" aria-label="{{ __('Visa rader') }}">
                @foreach($filters as $value => $label)
                    <button type="button" wire:click="$set('filter', '{{ $value }}')" @class(['rounded-full px-3 py-1', 'bg-accent-100 font-semibold text-accent-800' => $filter === $value, 'text-gray-600 hover:text-gray-900' => $filter !== $value]) aria-pressed="{{ $filter === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </nav>
            @if($canEdit && $ready > 0)
                <div x-data="{ confirming: false }" class="flex flex-wrap items-center gap-2 text-sm">
                    <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ __('Godkänn :count rader utan anmärkning', ['count' => $ready]) }}</x-button>
                    <span x-show="confirming" x-cloak class="flex flex-wrap items-center gap-2">
                        <span class="text-gray-700">{{ __('Raderna läggs in i lönekörningen som timmar och tillägg. Lönekörningen behöver beräknas igen.') }}</span>
                        <x-button type="button" wire:click="approveAll" wire:loading.attr="disabled">{{ __('Ja, godkänn') }}</x-button>
                        <button type="button" class="text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                    </span>
                </div>
            @endif
        </div>

        @if($lines->isEmpty())
            <x-ui.empty-state class="mt-4" :title="__('Inga rader här')">{{ __('Välj en annan vy för att se filens övriga rader.') }}</x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="py-2 pr-3">{{ __('Rad') }}</th>
                            <th class="py-2 pr-3">{{ __('Anställd') }}</th>
                            <th class="py-2 pr-3">{{ __('Kod') }}</th>
                            <th class="py-2 pr-3">{{ __('Datum') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('Antal') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('À-pris') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('Belopp') }}</th>
                            <th class="py-2 pr-3">{{ __('Status') }}</th>
                            <th class="py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($lines as $line)
                            <tr wire:key="line-{{ $line->id }}" class="align-top">
                                <td class="py-2 pr-3 tabular-nums text-gray-500">{{ $line->line_no }}</td>
                                <td class="py-2 pr-3">
                                    @if($line->employee)
                                        {{ $line->employee->employee_number }} {{ $line->employee->first_name }} {{ $line->employee->last_name }}
                                    @else
                                        <span class="text-gray-500">{{ trim(($line->employee_ref ?? '').' '.($line->person_ref ?? '')) ?: '–' }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3">
                                    <span class="font-mono">{{ $line->external_code ?? '–' }}</span>
                                    @if($line->payCode)<span class="block text-xs text-gray-500">{{ $line->payCode->name_sv }}</span>@endif
                                    @if($line->description)<span class="block text-xs text-gray-500">{{ $line->description }}</span>@endif
                                </td>
                                <td class="py-2 pr-3 tabular-nums">{{ $line->date_from?->toDateString() }}@if($line->date_to && ! $line->date_to->equalTo($line->date_from)) – {{ $line->date_to->toDateString() }}@endif</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $line->quantity !== null ? PayrollRunPage::hours((string) $line->quantity) : '' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $line->rate_ore?->format(withCurrency: false) }}</td>
                                <td class="py-2 pr-3 text-right">@if($line->amount_ore)<x-ui.amount :money="$line->amount_ore" />@endif</td>
                                <td class="py-2 pr-3">
                                    <x-ui.badge :tone="$line->status === 'APPROVED' ? 'success' : ($line->problems !== [] && $line->status === 'PROPOSED' ? 'accent' : 'neutral')">{{ $statuses[$line->status] ?? $line->status }}</x-ui.badge>
                                    @foreach($line->problems as $problem)<span class="mt-1 block text-xs text-accent-700">{{ PayrollFileImportPage::message($problem) }}</span>@endforeach
                                    @foreach($line->warnings as $warning)<span class="mt-1 block text-xs text-gray-600">{{ PayrollFileImportPage::message($warning) }}</span>@endforeach
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    @if($canEdit && $line->canBeApproved())
                                        <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="approve({{ $line->id }})">{{ __('Godkänn') }}</button>
                                    @endif
                                    @if($canEdit && $line->status === 'PROPOSED')
                                        <button type="button" class="ml-2 text-gray-600 underline" wire:click="reject({{ $line->id }})">{{ __('Avvisa') }}</button>
                                    @endif
                                    @if($canEdit && $line->status === 'APPROVED')
                                        <button type="button" class="text-gray-600 underline" wire:click="unapprove({{ $line->id }})">{{ __('Ångra') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $lines->links() }}</div>
        @endif
    </x-ui.card>
</div>
