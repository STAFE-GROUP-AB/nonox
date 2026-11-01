<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Ansökningar')" :lead="__('Sjukanmälningar och semesteransökningar från de anställda.')" />
        <livewire:payroll.absence-requests />
    </div>
</x-app-layout>
