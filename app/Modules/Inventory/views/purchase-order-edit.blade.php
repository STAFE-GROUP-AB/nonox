<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="$order ? $order->title() : __('Ny inköpsorder')" :lead="__('En inköpsorder bokförs inte. Den skickas till leverantören först när du har sett PDF:en och mottagaren.')" />
        <livewire:inventory.purchase-order-editor :order-id="$order?->id" />
    </div>
</x-app-layout>
