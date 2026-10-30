<x-app-layout>
    <div class="max-w-7xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="$invoice ? $invoice->title() : __('Ny leverantörsfaktura')" :lead="__('Fyll i det som står på fakturan. Beloppen på raderna är utan moms.')" />
        <livewire:payables.supplier-invoice-editor :invoice="$invoice" />
    </div>
</x-app-layout>
