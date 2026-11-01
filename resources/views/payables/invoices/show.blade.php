<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$invoice->title()" :lead="$invoice->supplierName().' · '.$invoice->invoice_date->toDateString()" />
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]">
            @if($invoice->file_path === null)
                <x-ui.empty-state :title="__('Ingen fil')">{{ __('Fakturan registrerades utan fil. Spara pappersfakturan, eller lägg till den som underlag på verifikationen.') }}</x-ui.empty-state>
            @elseif(str_starts_with((string) $invoice->file_mime, 'image/'))
                <img src="{{ route('payables.invoices.file', $invoice) }}" alt="{{ $invoice->file_name }}" class="w-full rounded-xl border border-gray-200 bg-white object-contain">
            @else
                <iframe src="{{ route('payables.invoices.file', $invoice) }}" title="{{ $invoice->title() }}" class="h-192 w-full rounded-xl border border-gray-200 bg-white"></iframe>
            @endif
            <livewire:payables.supplier-invoice-actions :invoice="$invoice" />
        </div>
    </div>
</x-app-layout>
