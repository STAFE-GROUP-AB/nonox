<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Analys')" :lead="__('Välj uppgifter, gruppera dem i rader och kolumner och jämför perioder. Klicka på ett belopp för att se posterna bakom det.')" />
        <livewire:analysis.analysis-page />
    </div>
</x-app-layout>
