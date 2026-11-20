<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Prisuppdatering :number', ['number' => $run->id])" :lead="__('Gamla och nya priser, och vad som inte kan ändras och varför.')" />
        <livewire:pricing.price-update-preview :run-id="$run->id" />
    </div>
</x-app-layout>
