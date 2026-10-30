<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Leverantörer')" :lead="__('Dem du köper av och betalar.')" />
        <x-ui.registers-nav />
        <livewire:payables.suppliers />
    </div>
</x-app-layout>
