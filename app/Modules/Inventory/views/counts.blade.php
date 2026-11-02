<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Inventering')" :lead="__('Räkna det som finns på hyllan. Skillnaderna förs in i lagret med en orsak.')" />
        <livewire:inventory.counts />
    </div>
</x-app-layout>
