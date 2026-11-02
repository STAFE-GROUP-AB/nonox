<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Komptid')" :lead="__('Övertid och mertid som tas ut som ledighet: saldo, uttag och utbetalning per anställd.')" />
        <livewire:payroll.comp-time />
    </div>
</x-app-layout>
