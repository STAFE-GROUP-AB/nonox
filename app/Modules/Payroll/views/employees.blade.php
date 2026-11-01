<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Anställda')" :lead="__('Personalen på lönelistan.')" />
        <livewire:payroll.employees />
    </div>
</x-app-layout>
