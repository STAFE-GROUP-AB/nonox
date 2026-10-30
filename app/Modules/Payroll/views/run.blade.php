<x-app-layout>
    <div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="$run->title()" :lead="__('Lägg till timmar och frånvaro, beräkna och attestera.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.run-page :run="$run" />
    </div>
</x-app-layout>
