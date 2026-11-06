<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Skattekontot')" :lead="__('Stäm av konto 1630 mot företagets skattekonto hos Skatteverket och bokför det som saknas, en transaktion i taget.')" />
        <livewire:accounting.tax-account-page />
    </div>
</x-app-layout>
