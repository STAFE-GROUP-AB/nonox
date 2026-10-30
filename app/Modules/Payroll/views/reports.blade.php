<x-app-layout>
    <div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Lönerapporter')" :lead="__('Lönerna per månad lönen betalas ut, från attesterade lönekörningar.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.reports />
    </div>
</x-app-layout>
