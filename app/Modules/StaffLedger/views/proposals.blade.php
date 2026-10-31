<x-app-layout>
    <div class="w-full space-y-8 px-4 py-10 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Tidrapporter från liggaren')" :lead="__('Föreslå timmar per dag till lönen. Liggaren och tidrapporterna hålls isär; inget skickas till lönen förrän förslaget godkänts.')" />
        @include('staff-ledger::partials.nav')
        <livewire:staff-ledger.proposals />
    </div>
</x-app-layout>
