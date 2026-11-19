<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="$template === null ? __('Ny mall för signering') : __('Ändra mall')" :lead="__('Rollerna är det som skiljer en mall från ett vanligt dokument: varje roll får ett steg, en kontroll och egna fält. Vem som tar rollen väljer du när du skickar.')" />
        <livewire:signing.template :template-id="$template" />
    </div>
</x-app-layout>
