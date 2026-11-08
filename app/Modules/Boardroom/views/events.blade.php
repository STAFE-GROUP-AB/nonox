<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Händelser i aktieboken')" :lead="__('Varje ändring av aktieboken med datum, vem som förde in den och en kontrollsumma. En felaktig händelse rättas med en ny som pekar på den; den gamla finns kvar.')" />
        <livewire:boardroom.events />
    </div>
</x-app-layout>
