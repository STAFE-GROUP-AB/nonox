<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6 print:py-0">
        <x-ui.page-heading :title="__('Konsolidering')" :lead="__('Räkna fram koncernens resultat och balans för en period. Varje körning sparas i en körlogg med kontrollsummor, och ingenting i företagens bokföring ändras.')" class="print:hidden" />
        <livewire:group.consolidation-page />
    </div>
</x-app-layout>
