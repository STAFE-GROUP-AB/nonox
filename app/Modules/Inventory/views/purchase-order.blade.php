<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="$order->title()" :lead="__('En inköpsorder är ett åtagande mot leverantören, inte bokföring. Lagret ökar när varorna tas emot, och kostnaden bokförs med leverantörsfakturan.')" />
        <livewire:inventory.purchase-order :order-id="$order->id" />
    </div>
</x-app-layout>
