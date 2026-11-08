<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Meny')" :lead="__('Varje rätt med portionskostnad, pris och marginal, och vad kassan har sålt under perioden.')" />
        <livewire:kitchen.menu />
    </div>
</x-app-layout>
