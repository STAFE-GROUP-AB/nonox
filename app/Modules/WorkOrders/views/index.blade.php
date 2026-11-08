<x-app-layout>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-10">
        <x-ui.page-heading :title="__('Arbetsorder')" :lead="__('Jobb hos kunder med tid, material och ÄTA. Arbetsordern blir fakturaunderlaget.')" />
        <livewire:work-orders.index />
    </div>
</x-app-layout>
