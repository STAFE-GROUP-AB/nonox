<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Verifikationer')" :lead="__('Allt som har bokförts, i ordning. En bokförd verifikation kan aldrig ändras, bara rättas.')" />
        <x-ui.bookkeeping-nav />
        <livewire:accounting.voucher-list />
    </div>
</x-app-layout>
