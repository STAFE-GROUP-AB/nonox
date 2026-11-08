<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Körjournal')" :lead="__('Bilar, resor och mätarställningar. Resorna skrivs in för hand; NONOX läser aldrig någons position.')" />
        <livewire:mileage.mileage-log-page />
    </div>
</x-app-layout>
