<x-app-layout>
    <div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Regler för lön')" :lead="__('Satser och belopp som lönekörningarna använder, med källa och kontroll.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.rules />
    </div>
</x-app-layout>
