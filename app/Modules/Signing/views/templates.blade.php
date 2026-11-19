<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Mallar för signering')" :lead="__('Roller, steg, kontroller, fält och inställningar som du använder om och om igen. En mall innehåller aldrig några undertecknares e-postadresser.')" />
        <livewire:signing.templates />
    </div>
</x-app-layout>
