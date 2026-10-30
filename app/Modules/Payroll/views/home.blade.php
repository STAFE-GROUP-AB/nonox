<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Lön')" :lead="__('Löner, lönebesked, semester och arbetsgivardeklaration.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.home />
    </div>
</x-app-layout>
