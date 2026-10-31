<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Bankavstämning')" :lead="__('Stäm av bank- och kassakonton mot kontoutdraget. Det som är bokfört men inte syns hos banken ännu förs vidare till nästa avstämning.')" />
        <x-ui.bookkeeping-nav />
        <livewire:accounting.bank-reconciliation-page />
    </div>
</x-app-layout>
