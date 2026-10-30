<x-payroll::portal-layout>
    <livewire:payroll.my-absence />
    <livewire:payroll.my-expenses />
    <x-ui.page-heading :title="__('Mina lönebesked')" :lead="__('Dina lönebesked som PDF, det senaste först.')" />
    <livewire:payroll.my-payslips />
</x-payroll::portal-layout>
