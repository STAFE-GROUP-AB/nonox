<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Avtalsversioner')" :lead="__('Avtalens värden med källor, giltighet och kontroll. En lönekörning stoppas tills versionen som gäller är kontrollerad.')" />
        @include('agreements::partials.tabs')
        <livewire:agreements.versions />
    </div>
</x-app-layout>
