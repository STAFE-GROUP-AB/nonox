<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Att godkänna')" :lead="__('Allt som väntar på att du attesterar det, på ett ställe. Själva attesten gör du på underlagets egen sida.')" />
        <livewire:approval.approval-queue />
    </div>
</x-app-layout>
