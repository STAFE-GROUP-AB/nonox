<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Budget')" :lead="__('Planera intäkter och kostnader per konto och månad, och följ upp dem mot bokföringen. Budgeten bokförs aldrig.')" />
        <livewire:accounting.budgets-page />
    </div>
</x-app-layout>
