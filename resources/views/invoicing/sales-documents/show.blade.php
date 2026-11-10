@php($isQuote = $kind === App\Models\SalesDocument::QUOTE)

<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$document?->title() ?? ($isQuote ? __('Ny offert') : __('Ny order'))" />
        <livewire:invoicing.sales-document-editor :kind="$kind" :document="$document" />
        {{-- Bills of materials and costing per row (ADR-071 §4, §6): internal, only with Tillverkning on. --}}
        @if($document && app(App\Modules\ModuleManager::class)->isEnabled('manufacturing', auth()->user()->currentCompany()) && auth()->user()->hasTeamPermission(auth()->user()->currentCompany(), 'manufacturing:view'))
            <livewire:manufacturing.row-structures :document-id="$document->id" :key="'row-structures-'.$document->id" />
        @endif
        {{-- Signing with a signature pad (ADR-046), core since ADR-075 §7: with or without CRM. --}}
        @if($isQuote && $document)
            <livewire:signing.panel :quote-id="$document->id" :key="'signing-quote-'.$document->id" />
        @endif
    </div>
</x-app-layout>
