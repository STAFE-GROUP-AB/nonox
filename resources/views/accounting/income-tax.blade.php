<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Inkomstdeklaration')" :lead="__('Inkomstdeklaration 2 för aktiebolag: räkenskapsschemat och skattemässiga justeringar ur bokföringen, som SRU-filer att lämna med Skatteverkets filöverföring.')" />
        <livewire:accounting.income-tax-page />
    </div>
</x-app-layout>
