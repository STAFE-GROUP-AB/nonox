<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Anläggningar')" :lead="__('Inventarier, datorer, maskiner och bilar som skrivs av över flera år. Avskrivningen bokförs en gång i månaden.')" />
        <x-ui.bookkeeping-nav />
        <livewire:accounting.fixed-assets-page />
    </div>
</x-app-layout>
