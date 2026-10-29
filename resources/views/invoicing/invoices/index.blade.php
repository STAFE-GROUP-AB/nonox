<x-app-layout>
    <div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Fakturor')" :lead="__('Skicka fakturor som PDF, se vad som är obetalt och registrera betalningar.')" />
        <x-ui.sales-nav />
        <livewire:invoicing.invoice-list />
    </div>
</x-app-layout>
