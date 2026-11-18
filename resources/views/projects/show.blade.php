<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$project->label()" :lead="__('Projektets resultat mot budget och kalkyl.')" />
        <livewire:projects.project-result :project="$project" />
    </div>
</x-app-layout>
