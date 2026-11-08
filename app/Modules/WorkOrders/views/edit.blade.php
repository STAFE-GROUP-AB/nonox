<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Ändra arbetsorder :number', ['number' => $workOrder->number])" />
        <livewire:work-orders.editor :work-order-id="$workOrder->id" />
    </div>
</x-app-layout>
