@php
    use App\Domain\Invoicing\InvoiceStatus;
    use App\Support\Money\Money;
@endphp

<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2">
        <x-ui.card>
            <p class="text-sm text-gray-500">{{ __('Obetalt hos kunder') }}</p>
            <p class="mt-1 text-2xl font-semibold"><x-ui.amount :money="Money::ofOre($outstanding)" /></p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-sm text-gray-500">{{ __('Förfallna fakturor') }}</p>
            <p @class(['mt-1 text-2xl font-semibold', 'text-accent-700' => $overdue > 0])>{{ $overdue }}</p>
        </x-ui.card>
    </div>

    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <nav class="flex flex-wrap gap-2" aria-label="{{ __('Visa fakturor') }}">
                @foreach(App\Livewire\Invoicing\InvoiceList::FILTERS as $key => $label)
                    <button type="button" wire:click="$set('filter', '{{ $key }}')" @class([
                        'rounded-full px-3 py-1 text-sm font-medium',
                        'bg-gray-900 text-white' => $filter === $key,
                        'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => $filter !== $key,
                    ])>{{ __($label) }}</button>
                @endforeach
            </nav>
            @if($canCreate)
                <a href="{{ route('invoicing.invoices.create') }}" class="inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Ny faktura') }}</a>
            @endif
        </div>

        @if($invoices->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga fakturor här')">
                {{ __('Skapa en faktura, kontrollera den och skicka den som PDF via e-post. Den bokförs automatiskt.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Nummer') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Fakturadatum') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Förfaller') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                            <th class="py-2 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($invoices as $invoice)
                            <tr wire:key="invoice-{{ $invoice->id }}">
                                <td class="py-2 pr-4">
                                    <a href="{{ $invoice->isDraft() ? route('invoicing.invoices.edit', $invoice) : route('invoicing.invoices.show', $invoice) }}" class="font-semibold text-accent-600 hover:text-accent-700">
                                        {{ $invoice->number ?? __('Utkast') }}@if($invoice->isCredit()) <span class="font-normal text-gray-500">({{ __('kredit') }})</span>@endif
                                    </a>
                                </td>
                                <td class="py-2 pr-4">{{ $invoice->customer?->name }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $invoice->invoice_date->toDateString() }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $invoice->isCredit() ? '' : $invoice->due_date->toDateString() }}</td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="$invoice->total_ore" /></td>
                                <td class="py-2">
                                    @if($invoice->isOverdue($today))
                                        <span class="inline-flex rounded-full bg-accent-50 px-3 py-1 text-xs font-medium text-accent-700">{{ __('Förfallen') }}</span>
                                    @else
                                        <x-ui.badge :tone="$invoice->status === InvoiceStatus::Paid ? 'success' : 'neutral'">{{ $invoice->status->label() }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $invoices->links() }}</div>
        @endif
    </x-ui.card>
</div>
