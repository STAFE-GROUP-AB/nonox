<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Pågående projekt')" :lead="__('Upparbetat men ej fakturerat, och fakturerat men ej upparbetat, på projekt utan arbetsorder vid balansdagen. Förslaget blir verifikationsutkast som du själv bokför.')" />
        <livewire:projects.project-wip-page />
    </div>
</x-app-layout>
