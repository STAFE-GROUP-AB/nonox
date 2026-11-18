<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Dokument')" :lead="__('Företagets avtal, försäkringar, tillstånd och andra dokument, med versioner och hur länge varje dokument ska sparas. Filerna lagras krypterade.')" />
        <livewire:documents.document-archive />
    </div>
</x-app-layout>
