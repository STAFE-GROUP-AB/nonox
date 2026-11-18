<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="$requisition ? $requisition->title() : __('Ny inköpsanmodan')" :lead="__('Skriv vad du behöver och när. Anmodan skickas till attest först när du säger till, och bokförs inte.')" />
        <livewire:inventory.requisition-editor :requisition-id="$requisition?->id" />
    </div>
</x-app-layout>
