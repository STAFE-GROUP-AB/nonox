@php($isQuote = $kind === App\Models\SalesDocument::QUOTE)

<x-app-layout>
    <div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading
            :title="$isQuote ? __('Offerter') : __('Order')"
            :lead="$isQuote ? __('Prisförslag till kunder. En godkänd offert blir en order eller en faktura.') : __('Beställningar, till exempel catering. Bekräfta dem och fakturera när de är levererade.')" />
        <x-ui.sales-nav />
        <livewire:invoicing.sales-documents :kind="$kind" />
    </div>
</x-app-layout>
