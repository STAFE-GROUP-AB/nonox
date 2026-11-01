<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Bankavstämning')" :lead="__('Stäm av bank- och kassakonton mot kontoutdraget. Det som är bokfört men inte syns hos banken ännu förs vidare till nästa avstämning.')" />
        <livewire:accounting.bank-reconciliation-page />
    </div>
</x-app-layout>
