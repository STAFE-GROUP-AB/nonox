<x-payroll::portal-layout>
    <livewire:payroll.my-absence />
    <x-ui.page-heading :title="__('Mina lönebesked')" :lead="__('Dina lönebesked som PDF, det senaste först.')" />
    <livewire:payroll.my-payslips />
</x-payroll::portal-layout>
