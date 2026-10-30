<x-app-layout>
    <div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Betalfiler')" :lead="__('Betala attesterade leverantörsfakturor med en fil till banken, i stället för att skriva in dem en och en.')" />
        <x-ui.purchases-nav />
        <livewire:payables.payment-batches />
    </div>
</x-app-layout>
