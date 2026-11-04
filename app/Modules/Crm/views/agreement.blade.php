<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="$agreementId === null ? __('Nytt avtal') : __('Avtal')" />
        <livewire:crm.agreement-card :agreement-id="$agreementId" />
    </div>
</x-app-layout>
