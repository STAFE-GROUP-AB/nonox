<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6 print:py-0">
        <x-ui.page-heading :title="__('Koncernöversikt')" :lead="__('Dina företag samlade i en intern översikt: resultat och balans bredvid varandra, och förslag på vad som kan elimineras.')" class="print:hidden" />
        <livewire:group.group-overview-page />
    </div>
</x-app-layout>
