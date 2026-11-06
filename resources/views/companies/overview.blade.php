<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Mina företag')" :lead="__('Alla företag du arbetar i och vad som väntar i vart och ett. Klicka på en uppgift för att öppna den i rätt företag.')" />
        <p class="text-sm"><a href="{{ route('group.overview') }}" class="font-medium text-accent-600 hover:text-accent-700 focus:outline-hidden focus:underline">{{ __('Koncernöversikt: resultat och balans för flera av dina företag bredvid varandra') }}</a></p>
        <livewire:company.company-overview-page />
    </div>
</x-app-layout>
