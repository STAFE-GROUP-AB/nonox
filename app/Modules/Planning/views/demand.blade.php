<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Bemanningsbehov')" :lead="__('Prognosen för försäljningen och hur många som behöver jobba, räknad från bokföringen, kassan och era egna bemanningsmål. Behovet visas i schemats dagvy och under Bemanning och kostnad.')" />
        <livewire:planning.demand />
    </div>
</x-app-layout>
