<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Mejlmallar')" :lead="__('Mejl ni skickar ofta, med fält för namn och företag. Används med Mejla med mall på en kund eller en person.')" />
        <livewire:crm.templates />
    </div>
</x-app-layout>
