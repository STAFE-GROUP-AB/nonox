<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Leverantörsfakturor')" :lead="__('Registrera fakturorna du får, attestera dem och håll koll på vad som ska betalas.')" />
        <x-ui.kpis :groups="['payables']" />
        <livewire:payables.supplier-invoice-list />
    </div>
</x-app-layout>
