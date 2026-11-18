<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Beställ från inköpsanmodan')" :lead="__('Välj en leverantör och de attesterade raderna som ska beställas. Raderna läggs på en ny inköpsorder som utkast.')" />
        <livewire:inventory.requisition-convert />
    </div>
</x-app-layout>
