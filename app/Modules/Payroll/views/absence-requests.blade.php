<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Ansökningar')" :lead="__('Sjukanmälningar och semesteransökningar från de anställda.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.absence-requests />
    </div>
</x-app-layout>
