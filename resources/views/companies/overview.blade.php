<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Mina företag')" :lead="__('Alla företag du arbetar i och vad som väntar i vart och ett. Klicka på en uppgift för att öppna den i rätt företag.')" />
        <livewire:company.company-overview-page />
    </div>
</x-app-layout>
