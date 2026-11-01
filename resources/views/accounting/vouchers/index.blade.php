<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Verifikationer')" :lead="__('Allt som har bokförts, i ordning. En bokförd verifikation kan aldrig ändras, bara rättas.')" />
        <x-ui.kpis :groups="['ledger']" />
        <livewire:accounting.voucher-list />
    </div>
</x-app-layout>
