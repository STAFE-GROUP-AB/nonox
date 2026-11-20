<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Prisavtal')" :lead="__('En kunds förhandlade priser under en period. Ett aktivt avtal går före kundens prislista på fakturor, offerter och order.')" />
        <livewire:pricing.price-agreements />
    </div>
</x-app-layout>
