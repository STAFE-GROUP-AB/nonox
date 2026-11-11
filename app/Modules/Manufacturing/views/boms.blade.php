<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Stycklistor')" :lead="__('Vad en produkt består av: komponenter med mängd och spill, och arbetssteg med minuter. Kostnaden räknas från lagrets värde och arbetsstegens timkostnad.')" />
        <livewire:manufacturing.boms />
    </div>
</x-app-layout>
