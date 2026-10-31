<x-app-layout>
    <div class="w-full space-y-8 px-4 py-10 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Personal i personalliggaren')" :lead="__('Alla som arbetar i lokalerna, även inhyrd personal. Kod, QR-kort och foto för kiosken.')" />
        @include('staff-ledger::partials.nav')
        <livewire:staff-ledger.people />
    </div>
</x-app-layout>
