<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Liggaren')" :lead="__('Alla in- och utcheckningar med rättelser. Inget kan ändras eller tas bort; fel rättas med en ny post.')">
            <x-slot:action>@include('staff-ledger::partials.control-visit-button')</x-slot:action>
        </x-ui.page-heading>
        <livewire:staff-ledger.ledger />
    </div>
</x-app-layout>
