<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <x-ui.page-heading :title="__('Kundportal')" :lead="__('Dina kunder loggar in och ser fakturor, kontoutdrag och offerter, och ställer frågor till dig.')" />
        <livewire:portal.admin />
    </div>
</x-app-layout>
