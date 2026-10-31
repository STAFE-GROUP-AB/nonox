<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('EU-rapporter')" :lead="__('Periodisk sammanställning och OSS, räknade ur fakturorna till kunder i andra EU-länder.')" />
        <x-ui.bookkeeping-nav />
        <livewire:vat.eu-reports />
    </div>
</x-app-layout>
