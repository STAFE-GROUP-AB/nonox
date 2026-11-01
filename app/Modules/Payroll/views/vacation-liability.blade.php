<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Semesterlöneskuld')" :lead="__('Skulden för intjänad semester per anställd.')" />
        <livewire:payroll.vacation-liability />
    </div>
</x-app-layout>
