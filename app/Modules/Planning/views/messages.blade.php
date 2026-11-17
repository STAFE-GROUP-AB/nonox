<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Meddelanden')" :lead="__('Skriv till personalen på ett pass, en dag, en vecka eller en avdelning. De läser på Min sida, och du ser vem som har läst.')" />
        <livewire:planning.messages />
    </div>
</x-app-layout>
