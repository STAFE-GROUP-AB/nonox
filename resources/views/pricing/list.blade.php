<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="$priceList->code.' · '.$priceList->name" :lead="__('Priserna i listan, med antalstrappor och datum.')" />
        <livewire:pricing.price-list-items :list-id="$priceList->id" />
    </div>
</x-app-layout>
