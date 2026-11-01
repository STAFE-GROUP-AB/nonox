@php($base = $isQuote ? 'invoicing.quotes' : 'invoicing.orders')

<x-ui.card>
    <div class="flex flex-wrap items-center gap-4">
        @if($canCreate)
            <a href="{{ route($base.'.create') }}" class="inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 max-sm:min-h-11 text-sm font-semibold text-white hover:bg-accent-700">{{ $isQuote ? __('Ny offert') : __('Ny order') }}</a>
        @endif
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Visa') }}">
            @foreach(['oppna' => __('Öppna'), 'alla' => __('Alla')] as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')" @class([
                    'rounded-full px-3 py-1 text-sm font-medium',
                    'bg-gray-900 text-white' => $filter === $key,
                    'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => $filter !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </nav>
    </div>

    @if($documents->isEmpty())
        <x-ui.empty-state class="mt-6" :title="$isQuote ? __('Inga offerter här') : __('Inga order här')">
            {{ $isQuote
                ? __('En offert är ett prisförslag till kunden. När kunden tackar ja blir den en order, och sedan en faktura, utan att du skriver något två gånger.')
                : __('En order är det kunden har beställt, till exempel en catering. Skicka en orderbekräftelse och gör en faktura av ordern när den är levererad.') }}
        </x-ui.empty-state>
    @else
        {{-- A table from sm up; on a phone every document is a small card. --}}
        <div class="mt-4 sm:overflow-x-auto">
            <table class="block w-full text-sm sm:table">
                <thead class="hidden sm:table-header-group">
                    <tr class="border-b border-gray-200 text-left text-gray-500">
                        <th class="py-2 pr-4 font-medium">{{ __('Nummer') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Datum') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ $isQuote ? __('Giltig till') : __('Leverans') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                        <th class="py-2 font-medium">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="block divide-y divide-gray-100 sm:table-row-group">
                    @foreach($documents as $document)
                        <tr wire:key="document-{{ $document->id }}" class="grid grid-cols-2 items-baseline gap-x-3 gap-y-1 py-3 sm:table-row sm:py-0">
                            <td class="sm:py-2 sm:pr-4"><a href="{{ route($base.'.show', $document) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $document->number }}</a></td>
                            <td class="order-2 col-span-2 sm:py-2 sm:pr-4">{{ $document->customer?->name }}</td>
                            <td class="order-3 text-gray-600 tabular-nums sm:py-2 sm:pr-4 sm:text-inherit">{{ $document->document_date->toDateString() }}</td>
                            <td class="order-5 text-gray-600 tabular-nums sm:py-2 sm:pr-4 sm:text-inherit">@if($document->until_date)<span class="text-xs sm:hidden">{{ $isQuote ? __('Giltig till') : __('Leverans') }} </span>{{ $document->until_date->toDateString() }}@endif</td>
                            <td class="order-4 text-right sm:py-2 sm:pr-4"><x-ui.amount :money="$document->total_ore" /></td>
                            <td class="order-1 text-right sm:py-2 sm:text-left"><x-ui.badge>{{ $document->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $documents->links() }}</div>
    @endif
</x-ui.card>
