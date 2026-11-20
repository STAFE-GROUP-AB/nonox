<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Prislistor')" :lead="__('Egna priser per kundgrupp, i andra valutor och för kampanjer. Utan prislista gäller artikelns pris.')" />
        <livewire:pricing.price-lists />
        {{-- Lägsta täckningsgrad (ADR-082 §9): off by default. --}}
        <livewire:pricing.floor-settings />
    </div>
</x-app-layout>
