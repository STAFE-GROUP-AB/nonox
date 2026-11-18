<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$project->label()" :lead="__('Projektets resultat mot budget och kalkyl.')" />
        <livewire:projects.project-result :project="$project" />
        {{-- The billing plan (ADR-081 E8) is not for a member limited to cost centres or projects. --}}
        @can('viewBillingPlan', $project)
            <livewire:projects.project-billing-plan :project="$project" />
        @endcan
    </div>
</x-app-layout>
