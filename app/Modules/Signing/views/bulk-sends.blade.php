<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Massutskick för signering')" :lead="__('Skicka samma dokument för signering till många, en signering per rad i en fil. Du ser och kontrollerar varje rad innan något skickas.')" />
        <livewire:signing.bulk-sends />
    </div>
</x-app-layout>
