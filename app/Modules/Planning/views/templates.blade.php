<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Mallar')" :lead="__('Veckor som återkommer. Lägg ut en mall på schemat som utkast, en eller flera gånger i följd.')" />
        <livewire:planning.templates />
    </div>
</x-app-layout>
