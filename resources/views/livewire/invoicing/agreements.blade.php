@php
    use App\Domain\Invoicing\AgreementStatus;
    use App\Support\Money\Money;
@endphp

<x-ui.card>
    <div class="flex flex-wrap items-center gap-4">
        @if($canCreate)
            <a href="{{ route('invoicing.agreements.create') }}" class="inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Nytt avtal') }}</a>
        @endif
        <p class="text-sm text-gray-700">{{ __('Aktiva avtal motsvarar :amount i månaden, exkl. moms.', ['amount' => Money::ofOre($monthly)->format()]) }}</p>
    </div>

    @if($agreements->isEmpty())
        <x-ui.empty-state class="mt-6" :title="__('Inga avtal ännu')">
            {{ __('Fakturerar du samma sak varje månad, till exempel ett lunchavtal med ett kontor eller uthyrning av en lokal? Lägg upp ett avtal så skapas fakturorna av sig själva.') }}
        </x-ui.empty-state>
    @else
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500">
                        <th class="py-2 pr-4 font-medium">{{ __('Avtal') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Hur ofta') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Nästa faktura') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                        <th class="py-2 font-medium">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($agreements as $agreement)
                        <tr wire:key="agreement-{{ $agreement->id }}">
                            <td class="py-2 pr-4"><a href="{{ route('invoicing.agreements.show', $agreement) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $agreement->number }} {{ $agreement->name }}</a></td>
                            <td class="py-2 pr-4">{{ $agreement->customer?->name }}</td>
                            <td class="py-2 pr-4">{{ $agreement->intervalLabel() }}</td>
                            <td class="py-2 pr-4 tabular-nums">{{ $agreement->status === AgreementStatus::Ended ? '–' : $agreement->next_invoice_date->toDateString() }}</td>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="$agreement->total_ore" /></td>
                            <td class="py-2"><x-ui.badge :tone="$agreement->status === AgreementStatus::Active ? 'success' : 'neutral'">{{ $agreement->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>
