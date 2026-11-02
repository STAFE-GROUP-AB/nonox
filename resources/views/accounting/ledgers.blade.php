<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Reskontra')" :lead="__('Obetalda kund- och leverantörsfakturor en viss dag, jämförda med saldot på 1510 och 2440.')" />
        <livewire:accounting.ledger-report />
    </div>
</x-app-layout>
