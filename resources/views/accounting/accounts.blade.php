<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Kontoplan')" :lead="__('Kontona du bokför på. Den är förifylld med de vanligaste kontona enligt BAS, och du kan lägga till fler.')" />
        <livewire:accounting.chart-of-accounts />
    </div>
</x-app-layout>
