<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Prisavtal :reference', ['reference' => $priceAgreement->reference()]).' · '.$priceAgreement->title" :lead="__('Villkoren, priserna och signeringen för avtalet.')" />
        <livewire:pricing.price-agreement-editor :agreement-id="$priceAgreement->id" />
    </div>
</x-app-layout>
