<x-app-layout>
    <div class="w-full space-y-8 px-4 py-10 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Lokaler och kiosker')" :lead="__('Lokalerna där personalliggare förs, hur personalen checkar in och vilka surfplattor som är kiosker.')" />
        @include('staff-ledger::partials.nav')
        <livewire:staff-ledger.locations />
    </div>
</x-app-layout>
