<x-app-layout>
    <div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="$invoice->title()" :lead="($invoice->customer_snapshot['name'] ?? '').' · '.$invoice->invoice_date->toDateString()" />
        <x-ui.sales-nav />
        <div class="grid gap-6 lg:grid-cols-[1fr_24rem]">
            <iframe src="{{ route('invoicing.invoices.pdf', $invoice) }}" title="{{ $invoice->title() }}" class="h-[48rem] w-full rounded-xl border border-gray-200 bg-white"></iframe>
            <livewire:invoicing.invoice-actions :invoice="$invoice" />
        </div>
    </div>
</x-app-layout>
