<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Återkommande uppgifter')" :lead="__('Uppgifter som kommer tillbaka varje månad, kvartal eller år. NONOX skapar periodens uppgift av sig själv.')" />
        <livewire:tasks.recurring-tasks />
    </div>
</x-app-layout>
