<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Budget mot utfall')" :lead="__('Resultatet för månaden och hittills i år jämfört med budgeten. En positiv avvikelse är alltid bättre än budget.')" />
        <livewire:accounting.budget-report />
    </div>
</x-app-layout>
