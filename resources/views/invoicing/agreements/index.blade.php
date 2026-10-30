<x-app-layout>
    <div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Avtal')" :lead="__('Avtalsfakturering: samma fakturor varje månad, kvartal eller år, utan att skriva dem på nytt.')" />
        <x-ui.sales-nav />
        <livewire:invoicing.agreements />
    </div>
</x-app-layout>
