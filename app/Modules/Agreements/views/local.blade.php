<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Lokala avtal')" :lead="__('Företagets lokala avvikelser från avtalet. De används i lönen först när en behörig person har kontrollerat dem.')" />
        @include('agreements::partials.tabs')
        <livewire:agreements.local />
    </div>
</x-app-layout>
