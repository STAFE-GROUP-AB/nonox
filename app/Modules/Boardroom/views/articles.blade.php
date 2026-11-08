<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Bolagsordning')" :lead="__('De delar av bolagsordningen som NONOX använder: hur aktieägarna kallas, aktieslagen och gränserna. En ny bolagsordning gäller först när den är registrerad hos Bolagsverket.')" />
        <livewire:boardroom.articles />
    </div>
</x-app-layout>
