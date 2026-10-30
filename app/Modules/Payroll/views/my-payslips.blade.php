<x-app-layout>
    <div class="max-w-2xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Mina lönebesked')" :lead="__('Dina lönebesked som PDF.')" />
        <livewire:payroll.my-payslips />
    </div>
</x-app-layout>
