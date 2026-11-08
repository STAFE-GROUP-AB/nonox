<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Personalmat')" :lead="__('Måltider till personalen, vid representation och till ägaren. Råvarorna dras från lagret som eget bruk; ingen verifikation och ingen moms bokas.')" />
        <livewire:kitchen.meals />
    </div>
</x-app-layout>
