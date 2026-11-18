<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Prognos')" :lead="__('Förväntad efterfrågan per artikel och vecka eller månad. Underlaget för behovsplaneringen.')" />
        <livewire:inventory.forecast />
    </div>
</x-app-layout>
