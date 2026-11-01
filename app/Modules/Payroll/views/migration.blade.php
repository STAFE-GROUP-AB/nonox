<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Flytt av lönedata')" :lead="__('Från ett tidigare lönesystem: anställda, belopp hittills i år, semestersaldon och gamla lönebesked.')" />
        <livewire:payroll.migration />
    </div>
</x-app-layout>
