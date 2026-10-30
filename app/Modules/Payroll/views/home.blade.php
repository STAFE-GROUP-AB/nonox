<x-app-layout>
    <div class="max-w-4xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Lön')" :lead="__('Löner, lönebesked, semester och arbetsgivardeklaration.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.home />
    </div>
</x-app-layout>
