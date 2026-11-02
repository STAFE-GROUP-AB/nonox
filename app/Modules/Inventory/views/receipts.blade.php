<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Inleveranser')" :lead="__('Varor som kommer från leverantörer. Ta emot allt på en gång eller i delar.')" />
        <livewire:inventory.receipts />
    </div>
</x-app-layout>
