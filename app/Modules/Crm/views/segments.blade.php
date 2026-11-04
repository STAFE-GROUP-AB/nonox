<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Segment')" :lead="__('Ett segment är ett sparat filter som väljer vilka kontaktpersoner ett utskick går till, till exempel efter status, taggar, ort eller roll. Vilka som är med räknas fram när utskicket skickas.')" />
        <livewire:crm.segments />
    </div>
</x-app-layout>
