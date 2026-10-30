<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Inbetalningar')" :lead="__('Läs in bankens fil med inbetalningar, så prickas fakturorna av på OCR-numret.')" />
        <x-ui.sales-nav />
        <livewire:invoicing.incoming-payments />
    </div>
</x-app-layout>
