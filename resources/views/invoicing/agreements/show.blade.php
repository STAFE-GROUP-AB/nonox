<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$agreement ? $agreement->title().' · '.$agreement->name : __('Nytt avtal')" :lead="__('Raderna faktureras likadant varje gång. Priser är utan moms.')" />
        <livewire:invoicing.agreement-editor :agreement="$agreement" />
    </div>
</x-app-layout>
