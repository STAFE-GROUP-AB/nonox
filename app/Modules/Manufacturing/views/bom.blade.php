<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="$artikel === null ? __('Ny stycklista') : __('Stycklista')" :lead="__('Komponenter med nettomängd och spill, och arbetssteg med minuter. Bruttomängden är det som tas ur lagret.')" />
        <livewire:manufacturing.bom :artikel="$artikel === null ? null : (int) $artikel" />
    </div>
</x-app-layout>
