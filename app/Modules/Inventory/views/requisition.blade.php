<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="$requisition->title()" :lead="__('En inköpsanmodan är en förfrågan, inte en beställning. Först när den är attesterad kan en inköpare lägga den på en inköpsorder.')" />
        <livewire:inventory.requisition :requisition-id="$requisition->id" />
    </div>
</x-app-layout>
