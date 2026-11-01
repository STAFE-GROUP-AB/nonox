<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Avtal')" :lead="__('Avtalsfakturering: samma fakturor varje månad, kvartal eller år, utan att skriva dem på nytt.')" />
        <livewire:invoicing.agreements />
    </div>
</x-app-layout>
