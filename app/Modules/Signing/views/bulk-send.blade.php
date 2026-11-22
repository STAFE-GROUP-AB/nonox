<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Massutskick')" :lead="__('Förhandsgranskning och resultat för ett massutskick.')" />
        <livewire:signing.bulk-send :bulk-send="$bulkSend" />
    </div>
</x-app-layout>
