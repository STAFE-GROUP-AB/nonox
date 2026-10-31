<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Inkomstdeklaration')" :lead="__('Inkomstdeklaration 2 för aktiebolag: räkenskapsschemat och skattemässiga justeringar ur bokföringen, som SRU-filer att lämna med Skatteverkets filöverföring.')" />
        <x-ui.bookkeeping-nav />
        <livewire:accounting.income-tax-page />
    </div>
</x-app-layout>
