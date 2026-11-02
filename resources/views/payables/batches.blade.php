<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Betalfiler')" :lead="__('Betala attesterade leverantörsfakturor med en fil till banken, i stället för att skriva in dem en och en.')" />
        <livewire:payables.payment-batches />
        <livewire:payables.payment-responses />
    </div>
</x-app-layout>
