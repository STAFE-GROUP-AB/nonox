<x-app-layout>
    <div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="$invoice?->title() ?? __('Ny faktura')" />
        <x-ui.sales-nav />
        <livewire:invoicing.invoice-editor :invoice="$invoice" />
    </div>
</x-app-layout>
