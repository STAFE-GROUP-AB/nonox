<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Utskick')" />
        <livewire:crm.mailing-editor :mailing-id="$mailingId" />
    </div>
</x-app-layout>
