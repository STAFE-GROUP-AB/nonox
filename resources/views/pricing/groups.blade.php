<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Kundgrupper')" :lead="__('Samla kunder som ska ha samma priser, och rikta kampanjer till dem.')" />
        <livewire:pricing.price-groups />
    </div>
</x-app-layout>
