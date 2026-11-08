<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Ny arbetsorder')" :lead="__('Kund, pris och plats. Omvänd byggmoms följer kunden och ROT eller RUT väljs här.')" />
        <livewire:work-orders.editor />
    </div>
</x-app-layout>
