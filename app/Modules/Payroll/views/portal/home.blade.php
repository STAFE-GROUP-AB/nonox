<x-payroll::portal-layout>
    {{-- Planering's part of Min sida; empty unless the module is on and the login is an employee. --}}
    <livewire:planning.my-schedule />
    <livewire:payroll.my-absence />
    <livewire:payroll.my-expenses />
    <x-ui.page-heading :title="__('Mina lönebesked')" :lead="__('Dina lönebesked som PDF, det senaste först.')" />
    <livewire:payroll.my-payslips />
</x-payroll::portal-layout>
