<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Behovsplanering')" :lead="__('Vad som behöver köpas in eller tillverkas vecka för vecka, utifrån prognos, kundordrar, stycklistor, lager och det som redan är beställt.')" />
        <livewire:mrp.page />
    </div>
</x-app-layout>
