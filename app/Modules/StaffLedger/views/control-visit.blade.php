<x-app-layout>
    <div class="w-full space-y-8 px-4 py-10 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Skatteverket är här')" :lead="__('Visa vem som är incheckad nu och liggaren för vald period. Personnummer visas i sin helhet och öppnandet loggas.')" />
        @include('staff-ledger::partials.nav')
        <livewire:staff-ledger.control-visit />
    </div>
</x-app-layout>
