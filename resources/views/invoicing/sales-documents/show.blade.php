@php($isQuote = $kind === App\Models\SalesDocument::QUOTE)

<x-app-layout>
    <div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="$document?->title() ?? ($isQuote ? __('Ny offert') : __('Ny order'))" />
        <x-ui.sales-nav />
        <livewire:invoicing.sales-document-editor :kind="$kind" :document="$document" />
    </div>
</x-app-layout>
