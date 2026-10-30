<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Semesterlöneskuld')" :lead="__('Skulden för intjänad semester per anställd.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.vacation-liability />
    </div>
</x-app-layout>
