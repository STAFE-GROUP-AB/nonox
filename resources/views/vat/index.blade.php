<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8 print:py-0">
        <x-ui.page-heading :title="__('Moms')" :lead="__('Momsdeklarationen räknas fram ur bokföringen. Kontrollera rutorna, lämna deklarationen hos Skatteverket och bokför sedan momsen här.')" class="print:hidden" />
        <div class="print:hidden"><x-ui.bookkeeping-nav /></div>
        <livewire:vat.vat-returns />
    </div>
</x-app-layout>
