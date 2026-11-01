<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('My tasks')" :lead="__('Dina uppgifter i alla företag du arbetar i. Varje uppgift öppnas i sitt eget företag.')" />
        <livewire:tasks.my-tasks />
    </div>
</x-app-layout>
