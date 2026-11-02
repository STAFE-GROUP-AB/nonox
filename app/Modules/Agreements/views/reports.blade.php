<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Fora och Collectum')" :lead="__('Underlag för att rapportera löner och premier för anställda under ett kollektivavtal.')" />
        @include('agreements::partials.tabs')
        <livewire:agreements.reports />
    </div>
</x-app-layout>
