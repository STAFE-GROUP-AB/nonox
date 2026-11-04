@php($isQuote = $kind === App\Models\SalesDocument::QUOTE)

<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$document?->title() ?? ($isQuote ? __('Ny offert') : __('Ny order'))" />
        <livewire:invoicing.sales-document-editor :kind="$kind" :document="$document" />
        {{-- CRM stage 3 (ADR-046): signing with a signature pad, while CRM is on. --}}
        @if($isQuote && $document && app(App\Modules\ModuleManager::class)->isEnabled('crm', auth()->user()->currentCompany()))
            <livewire:crm.signing-panel :quote-id="$document->id" :key="'signing-quote-'.$document->id" />
        @endif
    </div>
</x-app-layout>
