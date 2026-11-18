<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Elimineringar')" :lead="__('Förslag på elimineringar mellan koncernens företag, med båda sidor och eventuell differens, och koncernjournalen med godkända och manuella poster. Ingenting bokförs i företagens bokföring.')" />
        <livewire:group.eliminations-page />
    </div>
</x-app-layout>
