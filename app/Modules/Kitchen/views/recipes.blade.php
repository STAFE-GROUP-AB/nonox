<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Recept')" :lead="__('Rätter och grundrecept med ingredienser, svinn och utbyte. Kostnaden räknas från lagrets värde på ingredienserna.')" />
        <livewire:kitchen.recipes />
    </div>
</x-app-layout>
