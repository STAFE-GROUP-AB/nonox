<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Räkenskapsår')" :lead="__('Perioden som bokföringen och bokslutet gäller. Oftast ett kalenderår.')" />
        <x-ui.bookkeeping-nav />
        <livewire:accounting.fiscal-years />
    </div>
</x-app-layout>
