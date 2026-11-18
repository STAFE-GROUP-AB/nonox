<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Signeringar')" :lead="__('Alla dokument som är skickade för signering: offerter, avtal, ÄTA och egna dokument. Du ser bara det du har behörighet till.')" />
        <livewire:signing.envelopes />
    </div>
</x-app-layout>
