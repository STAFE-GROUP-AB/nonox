<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Tidrapporter från liggaren')" :lead="__('Föreslå timmar per dag till lönen. Liggaren och tidrapporterna hålls isär; inget skickas till lönen förrän förslaget godkänts.')">
            <x-slot:action>@include('staff-ledger::partials.control-visit-button')</x-slot:action>
        </x-ui.page-heading>
        <livewire:staff-ledger.proposals />
    </div>
</x-app-layout>
