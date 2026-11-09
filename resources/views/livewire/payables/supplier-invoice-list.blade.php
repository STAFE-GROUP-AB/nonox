@php
    use App\Domain\Payables\SupplierInvoiceStatus;

    $today = Carbon\CarbonImmutable::today();
@endphp

<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-center gap-4">
            @if($canCreate)
                <a href="{{ route('payables.invoices.create') }}" class="inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 max-sm:min-h-11 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Ny leverantörsfaktura') }}</a>
            @endif
            <nav class="flex flex-wrap gap-2" aria-label="{{ __('Visa leverantörsfakturor') }}">
                @foreach(App\Livewire\Payables\SupplierInvoiceList::FILTERS as $key => $label)
                    <button type="button" wire:click="$set('filter', '{{ $key }}')" @class([
                        'rounded-full px-3 py-1 text-sm font-medium',
                        'bg-gray-900 text-white' => $filter === $key,
                        'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => $filter !== $key,
                    ])>{{ __($label) }}</button>
                @endforeach
            </nav>
        </div>

        @if($invoices->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga leverantörsfakturor här')">
                {{ __('Ladda upp fakturan som PDF eller bild, fyll i belopp och konto och registrera den. Den bokförs, attesteras och betalas härifrån.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 sm:overflow-x-auto">
                <table class="block w-full text-sm sm:table">
                    <thead class="hidden sm:table-header-group">
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Nummer') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Leverantör') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Fakturanummer') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Förfaller') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Kvar') }}</th>
                            <th class="py-2 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="block divide-y divide-gray-100 sm:table-row-group">
                        @foreach($invoices as $invoice)
                            <tr wire:key="supplier-invoice-{{ $invoice->id }}" class="grid grid-cols-2 items-baseline gap-x-3 gap-y-1 py-3 sm:table-row sm:py-0">
                                <td class="sm:py-2 sm:pr-4">
                                    <a href="{{ $invoice->isDraft() ? route('payables.invoices.edit', $invoice) : route('payables.invoices.show', $invoice) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $invoice->isImported() ? ($invoice->invoice_number ?? $invoice->external_ref).' ('.__('tidigare program').')' : ($invoice->number ?? __('Utkast')) }}</a>
                                </td>
                                <td class="order-2 col-span-2 sm:py-2 sm:pr-4">{{ $invoice->supplier?->name }}</td>
                                <td class="order-3 text-gray-600 sm:py-2 sm:pr-4 sm:text-inherit">{{ $invoice->invoice_number }}</td>
                                <td class="order-5 text-gray-600 tabular-nums sm:py-2 sm:pr-4 sm:text-inherit"><span class="text-xs sm:hidden">{{ __('Förfaller') }} </span>{{ $invoice->due_date->toDateString() }}</td>
                                <td class="order-4 text-right sm:py-2 sm:pr-4"><x-ui.amount :money="$invoice->total_ore" />@if($invoice->isForeign())<span class="block text-xs text-gray-500 tabular-nums">{{ $invoice->formatForeign((int) $invoice->foreign_total_minor) }}</span>@endif</td>
                                <td class="order-6 text-right sm:py-2 sm:pr-4">@unless($invoice->status === SupplierInvoiceStatus::Paid || $invoice->isDraft())<span class="text-xs text-gray-600 sm:hidden">{{ __('Kvar') }} </span><x-ui.amount :money="$invoice->remaining()" />@if($invoice->isForeign())<span class="block text-xs text-gray-500 tabular-nums">{{ $invoice->formatForeign((int) $invoice->foreignRemaining()) }}</span>@endif @endunless</td>
                                <td class="order-1 text-right sm:py-2 sm:text-left">
                                    @if($invoice->isOverdue($today))
                                        <span class="inline-flex rounded-full bg-accent-50 px-3 py-1 text-xs font-medium text-accent-700">{{ __('Förfallen') }}</span>
                                    @else
                                        <x-ui.badge :tone="$invoice->status === SupplierInvoiceStatus::Paid ? 'success' : 'neutral'">{{ $invoice->status->label() }}</x-ui.badge>
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

    <x-ui.card :title="__('Attest')">
        <p class="text-sm text-gray-700">
            {{ $company->separate_supplier_approval
                ? __('Någon annan än den som registrerade en faktura måste attestera den innan den betalas, och någon annan än den som skapade en betalfil måste godkänna den.')
                : __('Den som registrerade en faktura får också attestera den, och den som skapade en betalfil får också godkänna den.') }}
        </p>
        @if($canChangeRule)
            <button type="button" class="mt-3 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="setSeparateApproval({{ $company->separate_supplier_approval ? 'false' : 'true' }})">
                {{ $company->separate_supplier_approval ? __('Tillåt att samma person registrerar och attesterar (för företag med en person)') : __('Kräv att någon annan attesterar') }}
            </button>
        @endif
    </x-ui.card>
</div>
