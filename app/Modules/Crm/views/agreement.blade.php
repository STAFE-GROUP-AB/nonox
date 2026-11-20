<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="$agreementId === null ? __('Nytt avtal') : __('Avtal')" />
        <livewire:crm.agreement-card :agreement-id="$agreementId" />
        @if($agreementId !== null)
            {{-- Linked customer price agreements (ADR-082 P5); shown only to those who may see prices. --}}
            <livewire:pricing.crm-agreement-prices :agreement-id="$agreementId" />
        @endif
    </div>
</x-app-layout>
