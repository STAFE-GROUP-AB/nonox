<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Lönekörningar')" :lead="__('En lönekörning per månad: beräkna, kontrollera, attestera.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.run-list />
    </div>
</x-app-layout>
