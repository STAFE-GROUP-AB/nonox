<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Personalliggare')" :lead="__('Vem som är på plats just nu, och det som behöver åtgärdas.')">
            <x-slot:action>@include('staff-ledger::partials.control-visit-button')</x-slot:action>
        </x-ui.page-heading>
        <livewire:staff-ledger.overview />
    </div>
</x-app-layout>
