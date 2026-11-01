<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Räkenskapsår')" :lead="__('Perioden som bokföringen och bokslutet gäller. Oftast ett kalenderår.')" />
        <livewire:accounting.fiscal-years />
    </div>
</x-app-layout>
