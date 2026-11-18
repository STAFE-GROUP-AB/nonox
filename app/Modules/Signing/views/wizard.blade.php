<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Skicka dokument för signering')" :lead="__('Välj dokument, ange vilka som ska signera och i vilken ordning, välj kontroll och sista dag, och skicka.')" />
        <livewire:signing.wizard :type="request()->query('typ')" :document-id="request()->query('dokument')" />
    </div>
</x-app-layout>
