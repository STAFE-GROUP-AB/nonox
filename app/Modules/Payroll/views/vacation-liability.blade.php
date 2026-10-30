<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Semesterlöneskuld')" :lead="__('Skulden för intjänad semester per anställd.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.vacation-liability />
    </div>
</x-app-layout>
