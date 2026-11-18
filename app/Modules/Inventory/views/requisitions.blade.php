<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Inköpsanmodan')" :lead="__('Be om varor eller tjänster. En attestant godkänner anmodan, och en inköpare gör den till en inköpsorder. En anmodan är en förfrågan och bokförs inte.')" />
        <livewire:inventory.requisitions />
    </div>
</x-app-layout>
