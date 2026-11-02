@php
    use App\Domain\Invoicing\InvoiceStatus;
@endphp

<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-center gap-4">
            @if($canCreate)
                <a href="{{ route('invoicing.invoices.create') }}" class="inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 max-sm:min-h-11 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Ny faktura') }}</a>
            @endif
            <nav class="flex flex-wrap gap-2" aria-label="{{ __('Visa fakturor') }}">
                @foreach(App\Livewire\Invoicing\InvoiceList::FILTERS as $key => $label)
                    <button type="button" wire:click="$set('filter', '{{ $key }}')" @class([
                        'rounded-full px-3 py-1 text-sm font-medium',
                        'bg-gray-900 text-white' => $filter === $key,
                        'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => $filter !== $key,
                    ])>{{ __($label) }}</button>
                @endforeach
            </nav>
        </div>

        @if($invoices->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga fakturor här')">
                {{ __('Skapa en faktura, kontrollera den och skicka den som PDF via e-post. Den bokförs automatiskt.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 sm:overflow-x-auto">
                <table class="block w-full text-sm sm:table">
                    <thead class="hidden sm:table-header-group">
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Nummer') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Fakturadatum') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Förfaller') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                            <th class="py-2 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="block divide-y divide-gray-100 sm:table-row-group">
                        @foreach($invoices as $invoice)
                            <tr wire:key="invoice-{{ $invoice->id }}" class="grid grid-cols-2 items-baseline gap-x-3 gap-y-1 py-3 sm:table-row sm:py-0">
                                <td class="sm:py-2 sm:pr-4">
                                    <a href="{{ $invoice->isDraft() ? route('invoicing.invoices.edit', $invoice) : route('invoicing.invoices.show', $invoice) }}" class="font-semibold text-accent-600 hover:text-accent-700">
                                        {{ $invoice->number ?? __('Utkast') }}@if($invoice->isCredit()) <span class="font-normal text-gray-500">({{ __('kredit') }})</span>@endif
                                    </a>
                                </td>
                                <td class="order-2 col-span-2 sm:py-2 sm:pr-4">{{ $invoice->customer?->name }}</td>
                                <td class="order-3 text-gray-600 tabular-nums sm:py-2 sm:pr-4 sm:text-inherit">{{ $invoice->invoice_date->toDateString() }}</td>
                                <td class="order-5 text-gray-600 tabular-nums sm:py-2 sm:pr-4 sm:text-inherit">@unless($invoice->isCredit())<span class="text-xs sm:hidden">{{ __('Förfaller') }} </span>{{ $invoice->due_date->toDateString() }}@endunless</td>
                                <td class="order-4 text-right sm:py-2 sm:pr-4"><x-ui.amount :money="$invoice->total_ore" />@if($invoice->isForeign())<span class="block text-xs text-gray-500 tabular-nums">{{ $invoice->formatForeign((int) $invoice->foreign_total_minor) }}@if($invoice->status === InvoiceStatus::Issued && $invoice->foreign_paid_minor !== 0) · {{ __('kvar :remaining', ['remaining' => $invoice->formatRemaining()]) }}@endif</span>@endif</td>
                                <td class="order-1 text-right sm:py-2 sm:text-left">
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
