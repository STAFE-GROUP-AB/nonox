<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Flytt av lönedata')" :lead="__('Från ett tidigare lönesystem: anställda, belopp hittills i år, semestersaldon och gamla lönebesked.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.migration />
    </div>
</x-app-layout>
