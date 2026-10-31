<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Bokslut')" :lead="__('Gå igenom checklistan, boka skatt och årets resultat, stäng året och disponera resultatet efter årsstämman.')" />
        <x-ui.bookkeeping-nav />
        <livewire:accounting.closing-page />
    </div>
</x-app-layout>
