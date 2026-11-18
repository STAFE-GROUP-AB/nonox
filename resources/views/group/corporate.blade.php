<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Koncernstruktur')" :lead="__('Företag och ägande enligt de innehav ni själva har registrerat, med räkenskapsårens status i varje företag. NONOX prövar inte om de utgör en koncern.')" />
        <livewire:group.corporate-group-page />
    </div>
</x-app-layout>
