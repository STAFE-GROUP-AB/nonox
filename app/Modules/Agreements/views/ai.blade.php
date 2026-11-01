<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Avtal med NONOX AI')" :lead="__('NONOX AI läser era dokument och föreslår avtal, lönegrupp och andra uppgifter per anställd. Inget förs in förrän du godkänner det.')" />
        @include('agreements::partials.tabs')
        <livewire:agreements.ai />
    </div>
</x-app-layout>
