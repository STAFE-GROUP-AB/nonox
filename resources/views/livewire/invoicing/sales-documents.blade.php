@php($base = $isQuote ? 'invoicing.quotes' : 'invoicing.orders')

<x-ui.card>
    <div class="flex flex-wrap items-center gap-4">
        @if($canCreate)
            <a href="{{ route($base.'.create') }}" class="inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ $isQuote ? __('Ny offert') : __('Ny order') }}</a>
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
        <table class="mt-4 w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-gray-500">
                    <th class="py-2 pr-4 font-medium">{{ __('Nummer') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Datum') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ $isQuote ? __('Giltig till') : __('Leverans') }}</th>
                    <th class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                    <th class="py-2 font-medium">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($documents as $document)
                    <tr wire:key="document-{{ $document->id }}">
                        <td class="py-2 pr-4"><a href="{{ route($base.'.show', $document) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $document->number }}</a></td>
                        <td class="py-2 pr-4">{{ $document->customer?->name }}</td>
                        <td class="py-2 pr-4 tabular-nums">{{ $document->document_date->toDateString() }}</td>
                        <td class="py-2 pr-4 tabular-nums">{{ $document->until_date?->toDateString() }}</td>
                        <td class="py-2 pr-4 text-right"><x-ui.amount :money="$document->total_ore" /></td>
                        <td class="py-2"><x-ui.badge>{{ $document->status->label() }}</x-ui.badge></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="mt-4">{{ $documents->links() }}</div>
    @endif
</x-ui.card>
