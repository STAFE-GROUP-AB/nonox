<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Tasks')" :lead="__('Det som ska göras i företaget, vem som gör det och när. Lämna en uppgift till en kollega eller till kunden.')" />
        <livewire:tasks.task-board />
    </div>
</x-app-layout>
