<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Rullande 12 månader')" :lead="__('De kommande tolv månaderna, också över årsskiftet: utfall och prognos där det finns en prognos, annars budgeten. Prognosen bokförs aldrig.')" />
        <livewire:accounting.rolling-forecast />
    </div>
</x-app-layout>
