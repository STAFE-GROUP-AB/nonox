<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$invoice?->title() ?? __('Ny faktura')" />
        <livewire:invoicing.invoice-editor :invoice="$invoice" />
        {{-- The company's own approval of a credit invoice draft (ADR-081 E21). --}}
        @if($invoice?->isCredit() && $invoice->isDraft() && ! App\Support\Tenancy\DimensionScope::limits(auth()->user(), $invoice->company_id))
            <livewire:approval.draft-approval-card type="CREDIT_INVOICE" :draft-id="$invoice->id" />
        @endif
    </div>
</x-app-layout>
