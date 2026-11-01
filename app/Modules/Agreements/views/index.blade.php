<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Avtal')" :lead="__('Kollektivavtalet i lönen: vilket avtal företaget är bundet av, och varje anställds avtal, lönegrupp och branschvana.')" />
        @include('agreements::partials.tabs')
        <livewire:agreements.page />
    </div>
</x-app-layout>
