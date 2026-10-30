<x-app-layout>
    <div class="w-full py-10 px-4 sm:px-6 lg:px-10 space-y-8">
        <x-ui.page-heading :title="__('Lönerapporter')" :lead="__('Lönerna per månad lönen betalas ut, från attesterade lönekörningar.')" />
        @include('payroll::partials.nav')
        <livewire:payroll.reports />
    </div>
</x-app-layout>
