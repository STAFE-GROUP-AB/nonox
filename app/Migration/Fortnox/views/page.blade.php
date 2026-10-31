<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Flytta från Fortnox')" :lead="__('Hämta bokföring, register och underlag direkt från ditt Fortnox-konto. NONOX läser bara; ingenting ändras i Fortnox.')" />
        <livewire:fortnox.move-page />
    </div>
</x-app-layout>
