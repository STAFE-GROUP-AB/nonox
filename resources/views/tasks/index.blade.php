<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Tasks')" :lead="__('Det som ska göras i alla dina företag, vem som gör det och när. Lämna en uppgift till kunden eller tillbaka till byrån.')" />
        <livewire:tasks.task-center />
    </div>
</x-app-layout>
