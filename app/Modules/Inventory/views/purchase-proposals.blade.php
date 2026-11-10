<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Inköpsförslag')" :lead="__('Vad som behöver beställas, utifrån lagersaldo, beställningspunkter, öppna kundordrar och det som redan är beställt.')" />
        <livewire:inventory.purchase-proposals />
    </div>
</x-app-layout>
