<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Kunder')" :lead="__('Dem du fakturerar.')" />
        <x-ui.registers-nav />
        <livewire:invoicing.customers />
    </div>
</x-app-layout>
