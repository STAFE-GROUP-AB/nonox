<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Skicka dokument för signering')" :lead="__('Välj dokument, ange vilka som ska signera och i vilken ordning, placera fält i dokumentet om du vill, välj kontroll och sista dag, och skicka.')" />
        <livewire:signing.wizard :type="is_string(request()->query('typ')) ? request()->query('typ') : null" :document-id="is_string(request()->query('dokument')) ? request()->query('dokument') : null" :template="is_string(request()->query('mall')) ? request()->query('mall') : null" />
    </div>
    {{-- The field editor of step 3 (PDF.js, loaded only when a document is on screen). --}}
    @vite('resources/js/signing-editor.js')
</x-app-layout>
