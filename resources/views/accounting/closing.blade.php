<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Bokslut')" :lead="__('Gå igenom checklistan, boka skatt och årets resultat, stäng året och disponera resultatet efter årsstämman.')" />
        <livewire:accounting.closing-page />
    </div>
</x-app-layout>
