@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($number = fn (string $digits): string => App\Modules\StaffLedger\Support\Export\ControlVisitReport::formatNumber($digits))
<div class="space-y-8">
    <x-ui.card :title="__('Incheckade just nu (:count)', ['count' => count($report->present)])">
        @if($report->present === [])
            <p class="text-sm text-gray-600">{{ __('Ingen är incheckad.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-base">
                    <thead><tr class="border-b border-stone-200 text-sm text-gray-600">
                        <th class="py-2 pe-4 text-start font-medium">{{ __('Namn') }}</th>
                        <th class="py-2 pe-4 text-start font-medium">{{ __('Person- eller samordningsnummer') }}</th>
                        <th class="py-2 pe-4 text-start font-medium">{{ __('Arbetsgivare') }}</th>
                        <th class="py-2 pe-4 text-start font-medium">{{ __('Lokal') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('Incheckad') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-stone-200">
                        @foreach($report->present as $shift)
                            <tr>
                                <td class="py-2 pe-4 font-semibold text-ink">{{ $shift->personName }}</td>
                                <td class="py-2 pe-4 tabular-nums">{{ $number($shift->personalNumber) }}</td>
                                <td class="py-2 pe-4">{{ $shift->employerName ? $shift->employerName.' ('.$shift->employerOrgNumber.')' : '' }}</td>
                                <td class="py-2 pe-4">{{ $report->locationName($shift->locationId) }}</td>
                                <td class="py-2 tabular-nums">{{ $shift->start?->format('Y-m-d H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Liggaren')">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.field for="cv-from" :label="__('Från')"><input id="cv-from" type="date" wire:model.live="from" class="{{ $input }}"></x-ui.field>
            <x-ui.field for="cv-to" :label="__('Till')"><input id="cv-to" type="date" wire:model.live="to" class="{{ $input }}"></x-ui.field>
            <x-ui.field for="cv-location" :label="__('Lokal')">
                <select id="cv-location" wire:model.live="location" class="{{ $input }}">
                    <option value="">{{ __('Alla lokaler') }}</option>
                    @foreach($locations as $place)
                        <option value="{{ $place->id }}">{{ $place->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="cv-search" :label="__('Sök namn eller personnummer')"><input id="cv-search" type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}"></x-ui.field>
        </div>

        <div class="mt-5 flex flex-wrap gap-3">
            @foreach(['pdf' => __('Ladda ner PDF'), 'csv' => __('Ladda ner CSV'), 'xml' => __('Ladda ner XML')] as $format => $label)
                <a href="{{ route('staff-ledger.control-visit.export', ['format' => $format, ...$exportQuery]) }}" class="inline-flex min-h-11 items-center rounded-lg bg-ink px-4 py-2 text-sm font-semibold text-white hover:bg-black">{{ $label }}</a>
            @endforeach
            <x-secondary-button type="button" wire:click="transfer">{{ __('Överför till Skatteverket') }}</x-secondary-button>
        </div>
        @unless($transferAvailable)
            <p class="mt-2 text-sm text-gray-600">{{ __('Skatteverket tar ännu inte emot liggaren elektroniskt. XML-filen följer NONOX:s eget, preliminära format tills Skatteverket publicerar sitt.') }}</p>
        @endunless

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-stone-200 text-gray-600">
                    <th class="py-2 pe-4 text-start font-medium">{{ __('Namn') }}</th>
                    <th class="py-2 pe-4 text-start font-medium">{{ __('Person- eller samordningsnummer') }}</th>
                    <th class="py-2 pe-4 text-start font-medium">{{ __('Arbetsgivare') }}</th>
                    <th class="py-2 pe-4 text-start font-medium">{{ __('Lokal') }}</th>
                    <th class="py-2 pe-4 text-start font-medium">{{ __('Början') }}</th>
                    <th class="py-2 pe-4 text-start font-medium">{{ __('Slut') }}</th>
                    <th class="py-2 text-start font-medium">{{ __('Anmärkning') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-stone-200">
                    @forelse($report->shifts as $shift)
                        <tr>
                            <td class="py-2 pe-4 font-medium">{{ $shift->personName }}</td>
                            <td class="py-2 pe-4 tabular-nums">{{ $number($shift->personalNumber) }}</td>
                            <td class="py-2 pe-4">{{ $shift->employerName ? $shift->employerName.' ('.$shift->employerOrgNumber.')' : '' }}</td>
                            <td class="py-2 pe-4">{{ $report->locationName($shift->locationId) }}</td>
                            <td class="py-2 pe-4 tabular-nums">{{ $shift->start?->format('Y-m-d H:i') ?? __('saknas') }}</td>
                            <td class="py-2 pe-4 tabular-nums">{{ $shift->end?->format('Y-m-d H:i') ?? ($shift->isOpen() ? __('incheckad') : __('saknas')) }}</td>
                            <td class="py-2">{{ $shift->corrected() ? __('Rättad eller manuell') : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-gray-600">{{ __('Inga arbetspass under perioden.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-4 text-sm text-gray-600">{{ __(':count registreringar under perioden, med rättelser. Alla finns i exporterna.', ['count' => $report->entries->count()]) }} <a href="{{ route('staff-ledger.ledger', array_filter(['fran' => $exportQuery['fran'] ?? null, 'till' => $exportQuery['till'] ?? null, 'lokal' => $exportQuery['lokal'] ?? null])) }}" class="font-semibold text-accent-700 underline">{{ __('Visa alla registreringar') }}</a></p>
    </x-ui.card>
</div>
