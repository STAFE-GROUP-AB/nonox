<x-app-layout>
    <div class="mx-auto w-full max-w-xl space-y-4 px-4 py-4">
        <x-ui.page-heading :title="__('Mina arbetsorder')" :lead="__('Jobben du är tilldelad. Tryck på ett jobb för att registrera tid och material.')" />
        <livewire:work-orders.mine />
    </div>
</x-app-layout>
