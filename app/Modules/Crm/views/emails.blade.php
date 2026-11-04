<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('E-post')" :lead="__('Mejl som loggas på kunderna genom att skicka en kopia till CRM, och de som väntar på att kopplas.')" />
        <livewire:crm.emails />
    </div>
</x-app-layout>
