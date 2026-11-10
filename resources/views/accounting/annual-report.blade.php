<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Årsredovisning')" :lead="__('Årsredovisning enligt K2, och för ett mindre aktiebolag som följer K3 en årsredovisning enligt K3 (Beta). Siffrorna hämtas ur bokföringen; du skriver förvaltningsberättelsen, föreslår utdelning och anger vem som skriver under.')" />
        <livewire:accounting.annual-report-page />
    </div>
</x-app-layout>
