<x-app-layout>
    <div class="w-full space-y-8 px-4 py-10 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Personalliggare')" :lead="__('Vem som är på plats just nu, och det som behöver åtgärdas.')" />
        @include('staff-ledger::partials.nav')
        <livewire:staff-ledger.overview />
    </div>
</x-app-layout>
