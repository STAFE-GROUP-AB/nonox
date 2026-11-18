<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Batcher och serienummer')" :lead="__('Vad som finns i lager per batch och serienummer, och varifrån och vart varje enhet har gått.')" />
        <livewire:inventory.lots />
    </div>
</x-app-layout>
