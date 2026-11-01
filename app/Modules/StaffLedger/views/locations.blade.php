<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Lokaler och kiosker')" :lead="__('Lokalerna där personalliggare förs, hur personalen checkar in och vilka surfplattor som är kiosker.')">
            <x-slot:action>@include('staff-ledger::partials.control-visit-button')</x-slot:action>
        </x-ui.page-heading>
        <livewire:staff-ledger.locations />
    </div>
</x-app-layout>
