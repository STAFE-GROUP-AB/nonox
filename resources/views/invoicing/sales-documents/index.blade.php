@php($isQuote = $kind === App\Models\SalesDocument::QUOTE)

<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading
            :title="$isQuote ? __('Offerter') : __('Order')"
            :lead="$isQuote ? __('Prisförslag till kunder. En godkänd offert blir en order eller en faktura.') : __('Beställningar, till exempel catering. Bekräfta dem och fakturera när de är levererade.')" />
        {{-- CRM stage 3 (ADR-046): a new quote from a template, while CRM is on. --}}
        @if($isQuote && app(App\Modules\ModuleManager::class)->isEnabled('crm', auth()->user()->currentCompany()))
            <livewire:crm.quote-from-template :boxed="true" />
        @endif
        <livewire:invoicing.sales-documents :kind="$kind" />
    </div>
</x-app-layout>
