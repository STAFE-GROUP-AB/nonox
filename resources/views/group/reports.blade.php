<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6 print:py-0">
        <x-ui.page-heading :title="__('Koncernrapporter')" :lead="__('En intern sammanställd resultaträkning och balansräkning för koncernen från en sparad konsolidering, med elimineringarna från koncernjournalen. Som CSV och PDF, alltid märkta som intern koncernrapport.')" class="print:hidden" />
        <livewire:group.group-reports-page />
    </div>
</x-app-layout>
