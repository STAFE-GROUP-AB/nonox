<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Lagerställen')" :lead="__('Lager, zoner, hyllor och fack. Koden blir platsens adress i plocklistor och på etiketter.')" />
        <livewire:inventory.locations />
    </div>
</x-app-layout>
