<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Arbetsgivardeklaration')" :lead="__('Skatt och arbetsgivaravgifter per utbetalningsmånad, för Skatteverket.')" />
        <livewire:payroll.agi />
    </div>
</x-app-layout>
