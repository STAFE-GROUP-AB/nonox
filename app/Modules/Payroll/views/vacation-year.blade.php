<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Semesterår')" :lead="__('Spara eller betala ut semesterdagar som inte tagits ut.')" />
        <livewire:payroll.vacation-year />
    </div>
</x-app-layout>
