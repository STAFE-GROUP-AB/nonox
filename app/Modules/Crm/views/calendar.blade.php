<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Kalender')" :lead="__('Dina planerade samtal, möten och uppgifter i din egen kalender: i telefonen, i Google Kalender, i Outlook eller i Apples Kalender.')" />
        <livewire:crm.calendar />
    </div>
</x-app-layout>
