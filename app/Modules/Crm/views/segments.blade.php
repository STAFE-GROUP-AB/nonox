<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Segment')" :lead="__('Sparade urval av kontaktpersoner, till exempel efter status, taggar, ort eller roll. Ett utskick går till ett segment.')" />
        <livewire:crm.segments />
    </div>
</x-app-layout>
