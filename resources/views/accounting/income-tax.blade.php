<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Inkomstdeklaration')" :lead="__('Inkomstdeklarationen ur bokföringen: Inkomstdeklaration 2 för aktiebolag, NE-bilagan för enskild firma och Inkomstdeklaration 4 för handelsbolag, med skattemässiga justeringar och SRU-filer till Skatteverket.')" />
        <livewire:accounting.income-tax-page />
    </div>
</x-app-layout>
