<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Prospektera')" :lead="__('Hitta nya kunder i företagsregistret: sök på bransch, ort, bolagsform och storlek, eller hitta företag som liknar dina bästa kunder. Lägg till dem som prospekt eller i en ringlista.')" />
        <livewire:crm.prospecting />
    </div>
</x-app-layout>
