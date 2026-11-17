<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Budget för personalkostnad')" :lead="__('Budgeten per vecka för hela företaget eller en avdelning, mot den beräknade kostnaden för passen i schemat. Schemat varnar när kostnaden närmar sig budgeten, men hindrar aldrig planeringen.')" />
        <livewire:planning.budget />
    </div>
</x-app-layout>
