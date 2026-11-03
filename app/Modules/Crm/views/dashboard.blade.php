<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Översikt för försäljningen')" :lead="__('Säljtrattens värde, hur stor andel av affärerna ni vinner, hur lång tid en affär tar och hur mycket som görs varje vecka.')" />
        <livewire:crm.dashboard />
    </div>
</x-app-layout>
