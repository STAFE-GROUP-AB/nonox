<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('EU-rapporter')" :lead="__('Periodisk sammanställning och OSS, räknade ur fakturorna till kunder i andra EU-länder.')" />
        <livewire:vat.eu-reports />
    </div>
</x-app-layout>
