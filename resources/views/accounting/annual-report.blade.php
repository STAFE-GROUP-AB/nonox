<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Årsredovisning')" :lead="__('Årsredovisning enligt K2. Siffrorna hämtas ur bokföringen; du skriver förvaltningsberättelsen, föreslår utdelning och anger vem som skriver under.')" />
        <x-ui.bookkeeping-nav />
        <livewire:accounting.annual-report-page />
    </div>
</x-app-layout>
