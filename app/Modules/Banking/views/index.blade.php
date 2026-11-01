<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Bank')" :lead="__('Bankkonton kopplade med BankID. Transaktioner och saldon hämtas automatiskt och föreslås mot bokföringen. NONOX kan bara läsa, aldrig betala.')" />
        <livewire:banking.page />
    </div>
</x-app-layout>
