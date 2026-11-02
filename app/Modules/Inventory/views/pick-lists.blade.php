<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Plocklistor')" :lead="__('Vad som ska hämtas för en order eller faktura, i den ordning du går genom lagret.')" />
        <livewire:inventory.pick-lists />
    </div>
</x-app-layout>
