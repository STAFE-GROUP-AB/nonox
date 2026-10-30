<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="$invoice?->title() ?? __('Ny faktura')" />
        <x-ui.sales-nav />
        <livewire:invoicing.invoice-editor :invoice="$invoice" />
    </div>
</x-app-layout>
