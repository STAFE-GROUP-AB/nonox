<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Periodiseringar')" :lead="__('Kostnader och intäkter som hör till andra månader än de bokförs i. Varje månad bokförs med ett klick.')" />
        <livewire:accounting.accruals-page />
    </div>
</x-app-layout>
