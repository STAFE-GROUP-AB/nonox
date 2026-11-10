<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('HQ – introduktion')" :lead="__('Hur långt de godkända har kommit: har de loggat in, skapat ett företag, flyttat in bokföringen och skickat sin första faktura. Listan visar bara ja eller nej och datum, inga belopp eller uppgifter ur bokföringen. Sortera på dem som är längst från målet för att se vem du bör ringa. Varje besök skrivs i behandlingshistoriken.')" />
        <livewire:hq.onboarding-progress />
    </div>
</x-app-layout>
