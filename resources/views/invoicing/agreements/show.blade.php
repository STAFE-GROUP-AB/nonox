<x-app-layout>
    <div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="$agreement ? $agreement->title().' · '.$agreement->name : __('Nytt avtal')" :lead="__('Raderna faktureras likadant varje gång. Priser är utan moms.')" />
        <livewire:invoicing.agreement-editor :agreement="$agreement" />
    </div>
</x-app-layout>
