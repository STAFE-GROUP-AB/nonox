<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$run->title()" :lead="__('Lägg till timmar och frånvaro, beräkna och attestera.')" />
        <livewire:payroll.run-page :run="$run" />
    </div>
</x-app-layout>
