<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Uppgifter')" :lead="__('Återkommande uppgifter som följer med passen, efter avdelning, kompetens, veckodag och klockslag.')" />
        <livewire:planning.tasks />
    </div>
</x-app-layout>
