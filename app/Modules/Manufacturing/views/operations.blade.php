<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Arbetssteg')" :lead="__('Stegen i verkstaden, som Kapning, Hyvling och Montering, med vad en timme kostar. Minuterna i stycklistorna räknas om till arbetskostnad med timkostnaden.')" />
        <livewire:manufacturing.operations />
    </div>
</x-app-layout>
