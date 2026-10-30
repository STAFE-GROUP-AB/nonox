<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Anställda')" :lead="__('Personalen på lönelistan.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.employees />
    </div>
</x-app-layout>
