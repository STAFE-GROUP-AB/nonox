<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Inkorg')" :lead="__('Dagsrapporter, fakturor och kvitton som skickats till företagets adress. Originalet sparas som underlag; du godkänner förslaget.')" />
        <livewire:inbox.workspace />
    </div>
</x-app-layout>
