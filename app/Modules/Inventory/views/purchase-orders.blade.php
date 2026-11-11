<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Inköpsorder')" :lead="__('Det du beställer från leverantörer. En inköpsorder bokförs inte; lagret ökar när varorna tas emot och kostnaden bokförs med leverantörsfakturan.')" />
        <livewire:inventory.purchase-orders />
    </div>
</x-app-layout>
