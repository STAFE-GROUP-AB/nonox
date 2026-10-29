<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Kontoplan')" :lead="__('Kontona du bokför på. Den är förifylld med de vanligaste kontona enligt BAS, och du kan lägga till fler.')" />
        <x-ui.bookkeeping-nav />
        <livewire:accounting.chart-of-accounts />
    </div>
</x-app-layout>
