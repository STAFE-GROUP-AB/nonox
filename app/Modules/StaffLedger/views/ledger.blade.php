<x-app-layout>
    <div class="w-full space-y-8 px-4 py-10 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Liggaren')" :lead="__('Alla in- och utcheckningar med rättelser. Inget kan ändras eller tas bort; fel rättas med en ny post.')" />
        @include('staff-ledger::partials.nav')
        <livewire:staff-ledger.ledger />
    </div>
</x-app-layout>
