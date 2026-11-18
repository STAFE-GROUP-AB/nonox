<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Alla dokument')" :lead="__('Företagets egna dokument och filerna i övriga delar av NONOX, på ett ställe. Du ser bara det du har rätt att öppna i respektive del.')" />
        <livewire:documents.document-overview-page />
    </div>
</x-app-layout>
