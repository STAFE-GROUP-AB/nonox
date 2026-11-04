<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Avtal')" :lead="__('Kundernas avtal med period, uppsägningstid och förlängning. Du får en uppgift innan det är dags att säga upp eller omförhandla.')" />
        <livewire:crm.agreements />
    </div>
</x-app-layout>
