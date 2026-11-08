<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Uppskjuten skatt')" :lead="__('För företag som följer K3: skillnaderna mellan redovisat och skattemässigt värde, den uppskjutna skatten på dem och bokföringen av förändringen i bokslutet.')" />
        <livewire:accounting.deferred-tax-page />
    </div>
</x-app-layout>
