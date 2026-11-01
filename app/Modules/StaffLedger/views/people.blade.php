<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Personal i personalliggaren')" :lead="__('Alla som arbetar i lokalerna, även inhyrd personal. Kod, QR-kort och foto för kiosken.')">
            <x-slot:action>@include('staff-ledger::partials.control-visit-button')</x-slot:action>
        </x-ui.page-heading>
        <livewire:staff-ledger.people />
    </div>
</x-app-layout>
